/* Einsatzdoku — Ende-zu-Ende-Krypto für das PatientInnendaten-Modul.
 *
 * Prinzip (angelehnt an Bitwarden):
 *  - Aus dem Login-Passwort leitet der Browser per PBKDF2 (310 000 Runden,
 *    SHA-256, nutzerspezifisches Salt) 512 Bit ab und teilt sie:
 *      · dataKey  (256 Bit): bleibt IM BROWSER, verschlüsselt Daten
 *      · authToken (256 Bit): geht statt des Passworts zum Server
 *    Der Server sieht das Passwort damit nie und kann nichts entschlüsseln.
 *  - Ein zufälliger Inhaltsschlüssel (CK) verschlüsselt die eigentlichen
 *    Daten (AES-256-GCM). CK liegt doppelt verpackt auf dem Server:
 *    einmal mit dem dataKey, einmal mit dem Wiederherstellungsschlüssel.
 *  - Passwort ändern = CK neu verpacken; die Daten bleiben unangetastet.
 */
'use strict';
const EdCrypto = (() => {

  const ITER = 310000;
  const te = new TextEncoder(), td = new TextDecoder();

  /* ---- Helfer: hex / base64 ------------------------------------------- */
  const toHex = buf => [...new Uint8Array(buf)]
    .map(b => b.toString(16).padStart(2, '0')).join('');
  const fromHex = hex => new Uint8Array(
    (hex.match(/../g) || []).map(h => parseInt(h, 16)));
  const toB64 = buf => btoa(String.fromCharCode(...new Uint8Array(buf)));
  const fromB64 = s => Uint8Array.from(atob(s), c => c.charCodeAt(0));

  function randomHex(nBytes) {
    return toHex(crypto.getRandomValues(new Uint8Array(nBytes)));
  }

  /* ---- Schlüsselableitung aus dem Passwort ---------------------------- */
  async function deriveKeys(password, saltHex) {
    const base = await crypto.subtle.importKey(
      'raw', te.encode(password), 'PBKDF2', false, ['deriveBits']);
    const bits = await crypto.subtle.deriveBits(
      { name: 'PBKDF2', hash: 'SHA-256', salt: fromHex(saltHex), iterations: ITER },
      base, 512);
    const all = new Uint8Array(bits);
    return {
      dataKeyHex: toHex(all.slice(0, 32)),     // bleibt lokal
      authToken:  toHex(all.slice(32, 64))     // ersetzt das Passwort zum Server
    };
  }

  /* ---- AES-256-GCM ----------------------------------------------------- */
  async function aesKey(keyHex, usages) {
    return crypto.subtle.importKey('raw', fromHex(keyHex),
      { name: 'AES-GCM' }, false, usages);
  }

  // Klartext (String) -> base64(iv || ciphertext)
  async function encrypt(keyHex, plaintext) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const key = await aesKey(keyHex, ['encrypt']);
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv },
      key, te.encode(plaintext));
    const out = new Uint8Array(iv.length + ct.byteLength);
    out.set(iv); out.set(new Uint8Array(ct), iv.length);
    return toB64(out);
  }

  // base64(iv || ciphertext) -> Klartext; wirft bei falschem Schlüssel
  async function decrypt(keyHex, blobB64) {
    const raw = fromB64(blobB64);
    const key = await aesKey(keyHex, ['decrypt']);
    const pt = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: raw.slice(0, 12) }, key, raw.slice(12));
    return td.decode(pt);
  }

  /* ---- Wiederherstellungsschlüssel ------------------------------------ */
  // 20 Zufallsbytes als Gruppen à 4 (Base32 ohne 0/O/1/I) — einmalig zeigen!
  const RC_CHARS = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
  function newRecoveryCode() {
    const raw = crypto.getRandomValues(new Uint8Array(20));
    let s = '';
    for (let i = 0; i < 20; i++) {
      s += RC_CHARS[raw[i] % RC_CHARS.length];
      if (i % 4 === 3 && i < 19) s += '-';
    }
    return s;
  }
  // Aus dem (normalisierten) Code einen AES-Schlüssel machen
  async function recoveryKeyHex(code) {
    const norm = code.toUpperCase().replace(/[^A-Z0-9]/g, '');
    const d = await crypto.subtle.digest('SHA-256', te.encode('edk-rc:' + norm));
    return toHex(d);
  }

  /* ---- Sitzung: dataKey / Inhaltsschlüssel ---------------------------- */
  const S_DK = 'edk', S_CK = 'pck';
  const setDataKey = hex => sessionStorage.setItem(S_DK, hex);
  const getDataKey = () => sessionStorage.getItem(S_DK);
  async function getContentKey(wrapPw) {
    let ck = sessionStorage.getItem(S_CK);
    if (ck) return ck;
    const dk = getDataKey();
    if (!dk || !wrapPw) return null;
    try {
      ck = await decrypt(dk, wrapPw);          // CK liegt als Hex im Wrap
      sessionStorage.setItem(S_CK, ck);
      return ck;
    } catch (e) { return null; }               // Wrap passt nicht (z. B. nach Reset)
  }
  const clearSession = () => { sessionStorage.removeItem(S_DK); sessionStorage.removeItem(S_CK); };

  /* ---- Backup-Container (.edbak v2) -----------------------------------
   * Aufbau:  "EDBAK2" 0x00 0x02 | Flag(1) | Salt(16) | IV(12) | AES-GCM
   * Flag:    1 = Inhalt gzip-komprimiert, 0 = roh
   * Schlüssel: PBKDF2-SHA256(Backup-Passwort, Salt, 310 000, 256 Bit)
   * AAD:     die ersten 9 Bytes (Magie + Flag) — Kopfmanipulation fliegt auf.
   * Der Inhalt ist bereits KLARTEXT: Der Browser entschlüsselt vor dem
   * Versiegeln, damit sich das Backup in jedes Konto einspielen lässt.
   */
  const MAGIC2 = new Uint8Array([69, 68, 66, 65, 75, 50, 0, 2]);   // "EDBAK2"

  async function fileKey(password, salt) {
    const base = await crypto.subtle.importKey('raw', te.encode(password),
      'PBKDF2', false, ['deriveBits']);
    const bits = await crypto.subtle.deriveBits(
      { name: 'PBKDF2', salt, iterations: ITER, hash: 'SHA-256' }, base, 256);
    return crypto.subtle.importKey('raw', bits, 'AES-GCM', false, ['encrypt', 'decrypt']);
  }

  async function gzip(bytes) {
    if (typeof CompressionStream === 'undefined') return null;
    const s = new Blob([bytes]).stream().pipeThrough(new CompressionStream('gzip'));
    return new Uint8Array(await new Response(s).arrayBuffer());
  }
  async function gunzip(bytes) {
    const s = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'));
    return new Uint8Array(await new Response(s).arrayBuffer());
  }

  async function sealBackup(password, jsonText) {
    const raw = te.encode(jsonText);
    const packed = await gzip(raw);
    const flag = packed ? 1 : 0;
    const body = packed || raw;
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const head = new Uint8Array(9);
    head.set(MAGIC2, 0); head[8] = flag;
    const key = await fileKey(password, salt);
    const ct = new Uint8Array(await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv, additionalData: head }, key, body));
    const out = new Uint8Array(9 + 16 + 12 + ct.length);
    out.set(head, 0); out.set(salt, 9); out.set(iv, 25); out.set(ct, 37);
    return out;
  }

  async function openBackup(password, bytes) {
    const head = bytes.slice(0, 9);
    for (let i = 0; i < 8; i++) {
      if (head[i] !== MAGIC2[i]) throw new Error('Keine .edbak-Datei (Version 2).');
    }
    const key = await fileKey(password, bytes.slice(9, 25));
    let body;
    try {
      body = new Uint8Array(await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: bytes.slice(25, 37), additionalData: head },
        key, bytes.slice(37)));
    } catch (e) { throw new Error('Passwort falsch oder Datei beschädigt.'); }
    if (head[8] === 1) { body = await gunzip(body); }
    return JSON.parse(td.decode(body));
  }

  /** Ist das eine Backup-Datei dieses Programms? */
  function isBackupFile(bytes) {
    if (!bytes || bytes.length < 40) return false;
    for (let i = 0; i < 8; i++) { if (bytes[i] !== MAGIC2[i]) return false; }
    return true;
  }

  return { deriveKeys, encrypt, decrypt, randomHex,
           newRecoveryCode, recoveryKeyHex,
           setDataKey, getDataKey, getContentKey, clearSession,
           sealBackup, openBackup, isBackupFile };
})();
