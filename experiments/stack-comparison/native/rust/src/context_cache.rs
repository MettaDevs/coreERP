//! Cache konteks per proses untuk `CONTEXT_CACHE=on` (`../../optimized.md` bagian 2). Isinya hanya data
//! per user, keanggotaan, dan tenant yang disebut kuncinya, untuk satu versi `security_context_versions`.
//! Pemilihan tempat kerja dan apa pun yang khas request tidak pernah disimpan di sini.

use crate::{
    auth::Policies,
    db::{Cached, Client, Result},
};
use serde_json::Value;
use std::{
    collections::{HashMap, HashSet},
    hash::Hash,
    sync::{Arc, Mutex, MutexGuard, PoisonError},
};

/// Bila jumlah entri akan melewati batas ini, seluruh entri dikosongkan.
const MAX_ENTRIES: usize = 20_000;
const VERSION: &str = "select version from security_context_versions where id = 1";
/// Query batas validitas berikutnya dari `optimized.md`, apa adanya di bagian dalam. Bagian luar hanya
/// mengubah `timestamp` menjadi milidetik epoch dengan TimeZone sesi, sama dengan perbandingan `> now()`.
const BOUNDARY: &str = "select floor(extract(epoch from boundary.min::timestamptz) * 1000)::bigint from (select min(t) from (
  select a.valid_until as t from role_assignments a where a.membership_id = $1 and a.status = 'active' and a.valid_until > now()
  union all select a.valid_from from role_assignments a where a.membership_id = $1 and a.status = 'active' and a.valid_from > now()
  union all select s.valid_until from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id
            where a.membership_id = $1 and s.tenant_id = $2 and s.valid_until > now()
  union all select s.valid_from from role_assignment_data_policy_scopes s join role_assignments a on a.id = s.role_assignment_id
            where a.membership_id = $1 and s.tenant_id = $2 and s.valid_from > now()
) boundaries) boundary";

/// Nilai beserta saat kedaluwarsanya dalam milidetik epoch.
pub type Slot<K, T> = HashMap<K, (i64, Arc<T>)>;
/// Memilih jenis entri di dalam `State`, misalnya `State::users`.
pub type Select<K, T> = fn(&mut State) -> &mut Slot<K, T>;

#[derive(Default)]
pub struct State {
    version: Option<i64>,
    users: Slot<i64, Option<Value>>,
    memberships: Slot<i64, Vec<Value>>,
    policies: Slot<(String, String), Policies>,
    organizations: Slot<String, Vec<Value>>,
    permissions: Slot<(String, String), HashSet<String>>,
}

impl State {
    /// Baris `users` per `user_id`.
    pub fn users(&mut self) -> &mut Slot<i64, Option<Value>> {
        &mut self.users
    }
    /// Keanggotaan aktif beserta tenant per `user_id`.
    pub fn memberships(&mut self) -> &mut Slot<i64, Vec<Value>> {
        &mut self.memberships
    }
    /// Scope kebijakan data per (`membership_id`, `tenant_id`).
    pub fn policies(&mut self) -> &mut Slot<(String, String), Policies> {
        &mut self.policies
    }
    /// Organisasi aktif per `tenant_id`.
    pub fn organizations(&mut self) -> &mut Slot<String, Vec<Value>> {
        &mut self.organizations
    }
    /// Kode izin hasil role rekursif per (`membership_id`, `tenant_id`).
    pub fn permissions(&mut self) -> &mut Slot<(String, String), HashSet<String>> {
        &mut self.permissions
    }
    fn len(&self) -> usize {
        self.users.len()
            + self.memberships.len()
            + self.policies.len()
            + self.organizations.len()
            + self.permissions.len()
    }
}

pub struct ContextCache {
    ttl_ms: i64,
    state: Mutex<State>,
}

/// Versi yang dibaca satu request. Entri hanya dibaca dan disimpan selama versi proses masih sama, jadi
/// request yang versinya sudah tertinggal tidak memakai entri versi lain dan tidak menyimpan datanya.
#[derive(Clone, Copy)]
pub struct Lookup<'a> {
    cache: &'a ContextCache,
    pub version: i64,
}

impl ContextCache {
    pub fn new(ttl_seconds: u32) -> Self {
        Self {
            ttl_ms: i64::from(ttl_seconds) * 1000,
            state: Mutex::new(State::default()),
        }
    }

    /// Satu-satunya query versi per request. Versi yang berbeda mengosongkan seluruh cache proses.
    pub async fn observe(&self, db: &Client) -> Result<Lookup<'_>> {
        let version: i64 = db.cached_query_one(VERSION, &[]).await?.try_get(0)?;
        Ok(self.sync(version))
    }

    /// Versi yang sudah dibaca request ini, tanpa query ulang.
    pub fn lookup(&self, version: i64) -> Lookup<'_> {
        Lookup {
            cache: self,
            version,
        }
    }

    fn sync(&self, version: i64) -> Lookup<'_> {
        let mut state = self.lock();
        if state.version != Some(version) {
            *state = State {
                version: Some(version),
                ..State::default()
            };
        }
        self.lookup(version)
    }

    /// Kunci hanya dipegang untuk operasi map, tidak pernah melewati `.await`.
    fn lock(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(PoisonError::into_inner)
    }

    fn expiry(&self, now: i64, boundary: Option<i64>) -> i64 {
        let ttl = now + self.ttl_ms;
        boundary.map_or(ttl, |boundary| boundary.min(ttl))
    }
}

impl Lookup<'_> {
    fn get<K: Eq + Hash, T>(&self, select: Select<K, T>, key: &K, now: i64) -> Option<Arc<T>> {
        let mut state = self.cache.lock();
        if state.version != Some(self.version) {
            return None;
        }
        let slot = select(&mut state);
        let hit = slot
            .get(key)
            .filter(|(expires_at, _)| now < *expires_at)
            .map(|(_, value)| value.clone());
        if hit.is_none() {
            slot.remove(key);
        }
        hit
    }

    fn put<K: Eq + Hash, T>(&self, select: Select<K, T>, key: K, expires_at: i64, value: Arc<T>) {
        let mut state = self.cache.lock();
        if state.version != Some(self.version) {
            return;
        }
        if state.len() >= MAX_ENTRIES {
            *state = State {
                version: state.version,
                ..State::default()
            };
        }
        select(&mut state).insert(key, (expires_at, value));
    }
}

fn now_ms() -> i64 {
    chrono::Utc::now().timestamp_millis()
}

/// Entri dari cache; bila miss, `compute` (query yang sama dengan hari ini) dijalankan lalu disimpan sampai
/// TTL. Tanpa cache, `compute` dijalankan apa adanya.
pub async fn fetch<K: Eq + Hash, T>(
    lookup: Option<Lookup<'_>>,
    select: Select<K, T>,
    key: K,
    compute: impl Future<Output = Result<T>>,
) -> Result<Arc<T>> {
    let Some(lookup) = lookup else {
        return Ok(Arc::new(compute.await?));
    };
    let now = now_ms();
    if let Some(value) = lookup.get(select, &key, now) {
        return Ok(value);
    }
    let value = Arc::new(compute.await?);
    lookup.put(select, key, lookup.cache.expiry(now, None), value.clone());
    Ok(value)
}

/// Seperti `fetch` untuk entri kebijakan dan izin. Saat miss, batas validitas berikutnya dibaca sebelum
/// data, sehingga penugasan yang berakhir atau mulai karena waktu sudah tercermin pada data yang dibaca
/// sesudahnya dan entri tidak pernah bertahan melewati batas itu.
pub async fn fetch_bounded<T>(
    lookup: Option<Lookup<'_>>,
    db: &Client,
    select: Select<(String, String), T>,
    membership_id: &str,
    tenant_id: &str,
    compute: impl Future<Output = Result<T>>,
) -> Result<Arc<T>> {
    let Some(lookup) = lookup else {
        return Ok(Arc::new(compute.await?));
    };
    let key = (membership_id.to_owned(), tenant_id.to_owned());
    let now = now_ms();
    if let Some(value) = lookup.get(select, &key, now) {
        return Ok(value);
    }
    let boundary: Option<i64> = db
        .cached_query_one(BOUNDARY, &[&membership_id, &tenant_id])
        .await?
        .try_get(0)?;
    let value = Arc::new(compute.await?);
    lookup.put(
        select,
        key,
        lookup.cache.expiry(now, boundary),
        value.clone(),
    );
    Ok(value)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::db::Error;
    use serde_json::json;

    fn key() -> (String, String) {
        ("membership".into(), "tenant".into())
    }

    #[test]
    fn version_change_clears_and_stale_request_neither_reads_nor_stores() {
        let cache = ContextCache::new(300);
        let first = cache.sync(1);
        first.put(State::users, 7, i64::MAX, Arc::new(Some(json!({"id":7}))));
        assert!(first.get(State::users, &7, 0).is_some());
        let second = cache.sync(2);
        assert!(second.get(State::users, &7, 0).is_none());
        // Request yang membaca versi 1 sebelum versi 2 terlihat tidak menulis ke cache versi 2.
        first.put(State::users, 7, i64::MAX, Arc::new(None));
        assert!(first.get(State::users, &7, 0).is_none());
        assert!(second.get(State::users, &7, 0).is_none());
        assert_eq!(cache.lock().len(), 0);
    }

    #[test]
    fn entry_expires_at_ttl_or_earlier_validity_boundary() {
        let cache = ContextCache::new(300);
        assert_eq!(cache.expiry(1_000, None), 301_000);
        assert_eq!(cache.expiry(1_000, Some(61_000)), 61_000);
        assert_eq!(cache.expiry(1_000, Some(900_000)), 301_000);
        let lookup = cache.sync(1);
        let permissions = Arc::new(HashSet::from(["management-aset.view".to_owned()]));
        lookup.put(State::permissions, key(), 61_000, permissions);
        assert!(lookup.get(State::permissions, &key(), 60_999).is_some());
        // Tepat di batas, penugasan yang berakhir sudah tidak aktif (`valid_until > now()`).
        assert!(lookup.get(State::permissions, &key(), 61_000).is_none());
        assert_eq!(cache.lock().len(), 0);
    }

    #[test]
    fn cache_is_cleared_instead_of_growing_past_bound() {
        let cache = ContextCache::new(300);
        let lookup = cache.sync(1);
        for user_id in 0..MAX_ENTRIES as i64 {
            lookup.put(State::users, user_id, i64::MAX, Arc::new(None));
        }
        assert_eq!(cache.lock().len(), MAX_ENTRIES);
        lookup.put(
            State::organizations,
            "tenant".into(),
            i64::MAX,
            Arc::new(vec![]),
        );
        assert_eq!(cache.lock().len(), 1);
        assert!(
            lookup
                .get(State::organizations, &"tenant".into(), 0)
                .is_some()
        );
    }

    #[tokio::test]
    async fn hit_shares_entry_without_running_query_and_off_mode_always_queries() {
        let cache = ContextCache::new(300);
        let lookup = Some(cache.sync(1));
        let memberships = vec![json!({"id":"a"}), json!({"id":"b"})];
        let found = async { Ok::<_, Error>(memberships.clone()) };
        let first = fetch(lookup, State::memberships, 7, found).await;
        let failing = async { Err::<Vec<Value>, _>(Error::new(500, "query tidak boleh berjalan")) };
        let second = fetch(lookup, State::memberships, 7, failing).await;
        assert!(Arc::ptr_eq(&first.unwrap(), &second.unwrap()));
        // Tanpa cache, query selalu dijalankan.
        let failing = async { Err::<Vec<Value>, _>(Error::new(500, "query berjalan")) };
        assert!(fetch(None, State::memberships, 7, failing).await.is_err());
    }
}
