use aes::Aes256;
use base64::{Engine, engine::general_purpose::STANDARD};
use cbc::cipher::{BlockDecryptMut, BlockEncryptMut, KeyIvInit, block_padding::Pkcs7};
use hmac::{Hmac, Mac};
use rand::{Rng, RngCore, distributions::Alphanumeric};
use serde_json::{Value, json};
use sha1::Sha1;
use sha2::Sha256;
use subtle::ConstantTimeEq;

#[derive(Clone)]
pub struct CookieCrypto {
    key: [u8; 32],
}

impl CookieCrypto {
    pub fn new(key: &str) -> std::result::Result<Self, &'static str> {
        let decoded = if let Some(base64) = key.strip_prefix("base64:") {
            STANDARD
                .decode(base64)
                .map_err(|_| "APP_KEY is not valid base64")?
        } else {
            key.as_bytes().to_vec()
        };
        Ok(Self {
            key: decoded
                .try_into()
                .map_err(|_| "APP_KEY must contain 32 bytes")?,
        })
    }
    fn prefix(&self, name: &str) -> String {
        let mut mac = Hmac::<Sha1>::new_from_slice(&self.key).unwrap();
        mac.update(format!("{name}v2").as_bytes());
        format!("{}|", hex::encode(mac.finalize().into_bytes()))
    }
    pub fn encrypt(&self, name: &str, value: &str) -> String {
        let mut iv = [0u8; 16];
        rand::thread_rng().fill_bytes(&mut iv);
        let data = format!("{}{value}", self.prefix(name));
        let encrypted = cbc::Encryptor::<Aes256>::new((&self.key).into(), (&iv).into())
            .encrypt_padded_vec_mut::<Pkcs7>(data.as_bytes());
        let iv = STANDARD.encode(iv);
        let value = STANDARD.encode(encrypted);
        let mut mac = Hmac::<Sha256>::new_from_slice(&self.key).unwrap();
        mac.update(format!("{iv}{value}").as_bytes());
        let envelope = json!({"iv": iv, "value": value, "mac": hex::encode(mac.finalize().into_bytes()), "tag": ""});
        STANDARD.encode(serde_json::to_vec(&envelope).unwrap())
    }
    pub fn decrypt(&self, name: &str, encrypted: &str) -> Option<String> {
        let value: Value = serde_json::from_slice(&STANDARD.decode(encrypted).ok()?).ok()?;
        let iv_text = value["iv"].as_str()?;
        let data_text = value["value"].as_str()?;
        let supplied = hex::decode(value["mac"].as_str()?).ok()?;
        let mut mac = Hmac::<Sha256>::new_from_slice(&self.key).ok()?;
        mac.update(format!("{iv_text}{data_text}").as_bytes());
        mac.verify_slice(&supplied).ok()?;
        let iv: [u8; 16] = STANDARD.decode(iv_text).ok()?.try_into().ok()?;
        let ciphertext = STANDARD.decode(data_text).ok()?;
        let plaintext = cbc::Decryptor::<Aes256>::new((&self.key).into(), (&iv).into())
            .decrypt_padded_vec_mut::<Pkcs7>(&ciphertext)
            .ok()?;
        let plaintext = String::from_utf8(plaintext).ok()?;
        let prefix = self.prefix(name);
        if plaintext.len() < prefix.len()
            || !bool::from(plaintext.as_bytes()[..prefix.len()].ct_eq(prefix.as_bytes()))
        {
            return None;
        }
        Some(plaintext[prefix.len()..].to_string())
    }
}
pub fn random_token() -> String {
    rand::thread_rng()
        .sample_iter(Alphanumeric)
        .take(40)
        .map(char::from)
        .collect()
}
pub fn constant_eq(left: &str, right: &str) -> bool {
    bool::from(left.as_bytes().ct_eq(right.as_bytes()))
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn validates_mac_and_cookie_name_before_accepting_session_id() {
        let crypto = CookieCrypto::new("01234567890123456789012345678901").unwrap();
        let encrypted = crypto.encrypt("coreerp_session", "session-id");
        assert_eq!(
            crypto.decrypt("coreerp_session", &encrypted).as_deref(),
            Some("session-id")
        );
        assert!(crypto.decrypt("XSRF-TOKEN", &encrypted).is_none());
        let mut envelope: Value =
            serde_json::from_slice(&STANDARD.decode(&encrypted).unwrap()).unwrap();
        envelope["mac"] = json!("0".repeat(64));
        assert!(
            crypto
                .decrypt(
                    "coreerp_session",
                    &STANDARD.encode(serde_json::to_vec(&envelope).unwrap())
                )
                .is_none()
        );
    }
}
