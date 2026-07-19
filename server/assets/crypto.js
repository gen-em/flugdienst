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

  return { deriveKeys, encrypt, decrypt, randomHex,
           newRecoveryCode, recoveryKeyHex,
           setDataKey, getDataKey, getContentKey, clearSession };
})();
