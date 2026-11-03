use crate::{
    App,
    crypto::{constant_eq, random_token},
    db::{self, Error, Result, flag, nullable, text},
};
use axum::{
    Json,
    http::{HeaderMap, HeaderValue, Method},
    response::{IntoResponse, Response},
};
use base64::{Engine, engine::general_purpose::STANDARD};
use md5::{Digest, Md5};
use rand::Rng;
use serde_json::{Value, json};
use std::collections::{HashMap, HashSet};
use tokio_postgres::Client;

pub struct Session {
    pub id: String,
    pub data: Value,
    pub user: Option<Value>,
}

#[cfg(test)]
mod tests {
    use super::*;

    fn context(grants: Vec<Value>) -> Context {
        Context {
            membership_id: "membership".into(),
            tenant_id: "tenant".into(),
            company_id: Some("company-a".into()),
            org_id: Some("unit-a".into()),
            permissions: HashSet::new(),
            policies: HashMap::from([("management-aset.asset-responsibility".into(), grants)]),
        }
    }

    #[test]
    fn restricted_grant_requires_company_and_unit() {
        let scope = context(vec![
            json!({"legal_entity_id":"company-a","organization_id":"unit-a","operating_unit_ids":["unit-a"]}),
        ]);
        assert!(scope.allows("company-a", Some("unit-a")));
        assert!(!scope.allows("company-a", Some("unit-b")));
        assert!(!scope.allows("company-b", Some("unit-a")));
        let company_only = context(vec![
            json!({"legal_entity_id":"company-a","organization_id":null,"operating_unit_ids":[]}),
        ]);
        assert!(!company_only.allows("company-a", Some("unit-a")));
        let unit_only = context(vec![
            json!({"legal_entity_id":null,"organization_id":"unit-a","operating_unit_ids":["unit-a"]}),
        ]);
        assert!(!unit_only.allows("company-a", Some("unit-a")));
        let unrestricted = context(vec![
            json!({"legal_entity_id":null,"organization_id":null,"operating_unit_ids":[]}),
        ]);
        assert!(unrestricted.allows("company-a", Some("unit-b")));
        assert!(!unrestricted.allows("company-b", Some("unit-b")));
    }

    #[test]
    fn limiter_keys_and_values_follow_laravel() {
        // md5("login" + email + "|" + ip), dihitung terpisah dengan hashlib Python.
        assert_eq!(
            route_key("someone@example.test", "172.17.0.1"),
            "c5115501bccc8b97cc271533cbe762f0"
        );
        assert_eq!(
            fortify_key("someone@example.test", "172.17.0.1"),
            "someone@example.test|172.17.0.1"
        );
        assert_eq!(serialize(0), "i:0;");
        assert_eq!(unserialize("i:1793750460;"), Some(1793750460));
        assert_eq!(unserialize("s:1:\"5\";"), None);
        let headers = rate_limit_headers(0, Some(42));
        assert_eq!(headers["x-ratelimit-limit"], "5");
        assert_eq!(headers["x-ratelimit-remaining"], "0");
        assert_eq!(headers["retry-after"], "42");
        let reset: i64 = headers["x-ratelimit-reset"]
            .to_str()
            .unwrap()
            .parse()
            .unwrap();
        assert!((reset - epoch() - 42).abs() <= 1);
        assert!(!rate_limit_headers(4, None).contains_key("retry-after"));
    }
}

impl Session {
    pub async fn load(app: &App, db: &Client, headers: &HeaderMap) -> Result<Self> {
        let cookie = headers
            .get("cookie")
            .and_then(|value| value.to_str().ok())
            .unwrap_or("")
            .split(';')
            .filter_map(|pair| pair.trim().split_once('='))
            .find(|(key, _)| *key == app.cookie_name)
            .and_then(|(_, value)| app.crypto.decrypt(&app.cookie_name, &url_decode(value)));
        let mut session = Self {
            id: random_token(),
            data: json!({}),
            user: None,
        };
        if let Some(id) = cookie
            .filter(|id| id.len() == 40 && id.bytes().all(|byte| byte.is_ascii_alphanumeric()))
        {
            if let Some(row) = db::optional(
                db,
                "select payload,last_activity from sessions where id=$1",
                &[&id],
            )
            .await?
            {
                if row["last_activity"].as_i64().unwrap_or(0)
                    >= chrono::Utc::now().timestamp() - 7200
                {
                    if let Ok(bytes) = STANDARD.decode(text(&row, "payload")) {
                        if let Ok(data) = serde_json::from_slice::<Value>(&bytes) {
                            if data.is_object() {
                                session.id = id;
                                session.data = data;
                            }
                        }
                    }
                }
            }
        }
        if !session.data["_token"].is_string() {
            session.data["_token"] = json!(random_token());
        }
        if let Some(user_id) = session.data.get(guard_key()).and_then(|value| {
            value
                .as_i64()
                .or_else(|| value.as_str().and_then(|s| s.parse().ok()))
        }) {
            session.user = db::optional(
                db,
                "select * from users where id=$1 and account_type='person'",
                &[&user_id],
            )
            .await?;
            if let Some(user) = &session.user {
                let actor = text(user, "id");
                db.query_one("select set_config('coreerp.user_id',$1,false)", &[&actor])
                    .await?;
            }
        }
        Ok(session)
    }

    pub fn csrf(
        &self,
        app: &App,
        method: &Method,
        headers: &HeaderMap,
        body: &Value,
    ) -> Result<()> {
        if matches!(*method, Method::GET | Method::HEAD | Method::OPTIONS)
            || headers.get("sec-fetch-site").and_then(|v| v.to_str().ok()) == Some("same-origin")
        {
            return Ok(());
        }
        let token = body["_token"]
            .as_str()
            .map(str::to_owned)
            .or_else(|| {
                headers
                    .get("x-csrf-token")
                    .and_then(|v| v.to_str().ok())
                    .map(str::to_owned)
            })
            .or_else(|| {
                headers
                    .get("x-xsrf-token")
                    .and_then(|v| v.to_str().ok())
                    .and_then(|value| app.crypto.decrypt("XSRF-TOKEN", &url_decode(value)))
            });
        if !token.is_some_and(|token| constant_eq(&token, &text(&self.data, "_token"))) {
            return Err(Error::new(419, "CSRF token mismatch."));
        }
        Ok(())
    }

    /// `POST /login` di belakang `throttle:login`, urutannya mengikuti `login-limiter.md`: limiter route
    /// memeriksa dan menghitung kunci `Kr` sebelum kredensial, Fortify menghitung `Kf` saat gagal dan
    /// menghapusnya saat sukses, lalu header sisa percobaan dibaca ulang sesudah handler.
    pub async fn login(
        &mut self,
        app: &App,
        db: &Client,
        body: &Value,
        ip: &str,
    ) -> Result<Response> {
        let email = text(body, "email").to_lowercase();
        let password = text(body, "password");
        if email.is_empty() || password.is_empty() {
            return Err(Error::validation("email", "The email field is required."));
        }
        let limiter = Limiter {
            db,
            prefix: &app.cache_prefix,
        };
        let route = route_key(&email, ip);
        if limiter.too_many_attempts(&route).await? {
            let retry_after = limiter.available_in(&route).await?;
            let mut error = Error::new(429, "Too Many Attempts.");
            error.headers = rate_limit_headers(0, Some(retry_after));
            return Err(error);
        }
        limiter.hit(&route).await?;
        let outcome = self
            .authenticate(db, &limiter, &fortify_key(&email, ip), &email, password)
            .await;
        if outcome
            .as_ref()
            .is_err_and(|error| error.status.is_server_error())
        {
            return outcome.map(|body| Json(body).into_response());
        }
        let remaining = (MAX_ATTEMPTS - limiter.attempts(&route).await?).max(0);
        let headers = rate_limit_headers(remaining, None);
        match outcome {
            Ok(body) => Ok((headers, Json(body)).into_response()),
            Err(mut error) => {
                error.headers.extend(headers);
                Err(error)
            }
        }
    }

    async fn authenticate(
        &mut self,
        db: &Client,
        limiter: &Limiter<'_>,
        fortify_key: &str,
        email: &str,
        password: String,
    ) -> Result<Value> {
        let user = db::optional(
            db,
            "select * from users where lower(email)=$1 and account_type='person' limit 1",
            &[&email],
        )
        .await?;
        let Some(user) = user else {
            return Err(login_failed(limiter, fortify_key).await);
        };
        let hash = text(&user, "password");
        let first_hash = hash.clone();
        let first_password = password.clone();
        let first = tokio::task::spawn_blocking(move || {
            bcrypt::verify(first_password, &first_hash).unwrap_or(false)
        })
        .await
        .unwrap_or(false);
        if !first {
            return Err(login_failed(limiter, fortify_key).await);
        }
        if !user["two_factor_secret"].is_null() && !user["two_factor_confirmed_at"].is_null() {
            return Err(Error::unsupported(
                "Autentikasi dua langkah belum dicakup kandidat ini.",
            ));
        }
        let second_user = db::one(
            db,
            "select * from users where lower(email)=$1 and account_type='person' limit 1",
            &[&email],
        )
        .await?;
        let second_hash = text(&second_user, "password");
        let second = tokio::task::spawn_blocking(move || {
            bcrypt::verify(password, &second_hash).unwrap_or(false)
        })
        .await
        .unwrap_or(false);
        if !second {
            return Err(login_failed(limiter, fortify_key).await);
        }
        db.execute("delete from sessions where id=$1", &[&self.id])
            .await?;
        self.id = random_token();
        self.data[guard_key()] = user["id"].clone();
        self.data["password_hash_web"] = json!(hash);
        self.data["_token"] = json!(random_token());
        let user_id = user["id"].as_i64().unwrap();
        db.execute(
            "update users set last_login_at=now(),updated_at=now() where id=$1",
            &[&user_id],
        )
        .await?;
        let actor = user_id.to_string();
        db.query_one("select set_config('coreerp.user_id',$1,false)", &[&actor])
            .await?;
        self.id = random_token();
        self.user = Some(user);
        limiter.clear(fortify_key).await?;
        Ok(json!({"two_factor":false}))
    }

    pub async fn save(&mut self, db: &Client, headers: &HeaderMap, ip: &str) -> Result<()> {
        self.data["_flash"] = json!({"old":[],"new":[]});
        let payload = STANDARD.encode(
            serde_json::to_vec(&self.data)
                .map_err(|_| Error::new(500, "Session encoding failed"))?,
        );
        let user_id = self.user.as_ref().and_then(|user| user["id"].as_i64());
        let agent = headers
            .get("user-agent")
            .and_then(|v| v.to_str().ok())
            .unwrap_or("");
        let epoch = chrono::Utc::now().timestamp() as i32;
        db.execute("insert into sessions (id,user_id,ip_address,user_agent,payload,last_activity) values ($1,$2,$3,$4,$5,$6) on conflict (id) do update set user_id=excluded.user_id,ip_address=excluded.ip_address,user_agent=excluded.user_agent,payload=excluded.payload,last_activity=excluded.last_activity", &[&self.id,&user_id,&ip,&agent,&payload,&epoch]).await?;
        if rand::thread_rng().gen_range(1..=100) <= 2 {
            db.execute(
                "delete from sessions where last_activity < $1",
                &[&(epoch - 7200)],
            )
            .await?;
        }
        Ok(())
    }
}

/// Kredensial ditolak: Fortify menghitung `Kf` sebelum menjawab 422.
async fn login_failed(limiter: &Limiter<'_>, fortify_key: &str) -> Error {
    match limiter.hit(fortify_key).await {
        Ok(_) => Error::validation("email", "These credentials do not match our records."),
        Err(error) => error,
    }
}

const MAX_ATTEMPTS: i64 = 5;
const DECAY_SECONDS: i64 = 60;
/// `Repository::FLEXIBLE_CREATED_KEY_PREFIX`, ikut dihapus bersama setiap kunci.
const FLEXIBLE_CREATED: &str = "illuminate:cache:flexible:created:";

/// Kunci `throttle:login`: `ThrottleRequests` menghash nama limiter dengan kunci dari `RateLimiter::for`.
fn route_key(email: &str, ip: &str) -> String {
    hex::encode(Md5::digest(format!("login{email}|{ip}").as_bytes()))
}
/// Kunci `LoginRateLimiter` Fortify.
fn fortify_key(email: &str, ip: &str) -> String {
    format!("{email}|{ip}")
}
fn epoch() -> i64 {
    chrono::Utc::now().timestamp()
}
fn serialize(value: i64) -> String {
    format!("i:{value};")
}
fn unserialize(value: &str) -> Option<i64> {
    value.strip_prefix("i:")?.strip_suffix(';')?.parse().ok()
}

fn rate_limit_headers(remaining: i64, retry_after: Option<i64>) -> HeaderMap {
    let mut headers = HeaderMap::new();
    headers.insert("x-ratelimit-limit", HeaderValue::from(MAX_ATTEMPTS));
    headers.insert("x-ratelimit-remaining", HeaderValue::from(remaining));
    if let Some(retry_after) = retry_after {
        headers.insert("retry-after", HeaderValue::from(retry_after));
        headers.insert(
            "x-ratelimit-reset",
            HeaderValue::from(epoch() + retry_after),
        );
    }
    headers
}

/// `DatabaseStore` dan `RateLimiter` Laravel sebatas yang dipakai login. Setiap method satu primitif
/// dengan statement dan batas transaksi yang sama seperti PHP; jangan digabung menjadi satu statement,
/// karena tujuannya pengukuran yang sebanding, bukan limiter tercepat.
struct Limiter<'a> {
    db: &'a Client,
    prefix: &'a str,
}

impl Limiter<'_> {
    fn keys(&self, key: &str) -> (String, String) {
        (
            format!("{}{key}", self.prefix),
            format!("{}{FLEXIBLE_CREATED}{key}", self.prefix),
        )
    }

    /// Baris yang kedaluwarsa dihapus lalu dianggap tidak ada. Nilai selain bilangan bulat dibaca 0.
    async fn get(&self, key: &str) -> Result<Option<i64>> {
        let (prefixed, flexible) = self.keys(key);
        let Some(row) = self
            .db
            .query_opt("select * from cache where key in ($1)", &[&prefixed])
            .await?
        else {
            return Ok(None);
        };
        let now = epoch();
        if row.try_get::<_, i64>("expiration")? <= now {
            self.db
                .execute(
                    "delete from cache where key in ($1,$2) and expiration <= $3",
                    &[&prefixed, &flexible, &now],
                )
                .await?;
            return Ok(None);
        }
        Ok(Some(
            unserialize(&row.try_get::<_, String>("value")?).unwrap_or(0),
        ))
    }

    async fn add(&self, key: &str, value: i64) -> Result<bool> {
        if self.get(key).await?.is_some() {
            return Ok(false);
        }
        let (prefixed, _) = self.keys(key);
        let inserted = self
            .db
            .execute(
                "insert into cache (key,value,expiration) values ($1,$2,$3) on conflict do nothing",
                &[&prefixed, &serialize(value), &(epoch() + DECAY_SECONDS)],
            )
            .await?;
        Ok(inserted > 0)
    }

    /// Transaksi sendiri dengan row lock. Expiration tidak disentuh; baris yang hilang menghasilkan 0.
    async fn increment(&self, key: &str) -> Result<i64> {
        let (prefixed, _) = self.keys(key);
        self.db.batch_execute("begin").await?;
        let result = async {
            let Some(row) = self
                .db
                .query_opt(
                    "select * from cache where key = $1 limit 1 for update",
                    &[&prefixed],
                )
                .await?
            else {
                return Ok(0);
            };
            let Some(current) = unserialize(&row.try_get::<_, String>("value")?) else {
                return Ok(0);
            };
            let hits = current + 1;
            self.db
                .execute(
                    "update cache set value = $1 where key = $2",
                    &[&serialize(hits), &prefixed],
                )
                .await?;
            Ok::<i64, Error>(hits)
        }
        .await;
        match result {
            Ok(hits) => {
                self.db.batch_execute("commit").await?;
                Ok(hits)
            }
            Err(error) => {
                self.db.batch_execute("rollback").await?;
                Err(error)
            }
        }
    }

    async fn put(&self, key: &str, value: i64) -> Result<()> {
        let (prefixed, _) = self.keys(key);
        self.db.execute("insert into cache (key,value,expiration) values ($1,$2,$3) on conflict (key) do update set value=excluded.value,expiration=excluded.expiration", &[&prefixed, &serialize(value), &(epoch() + DECAY_SECONDS)]).await?;
        Ok(())
    }

    async fn forget(&self, key: &str) -> Result<()> {
        let (prefixed, flexible) = self.keys(key);
        self.db
            .execute(
                "delete from cache where key in ($1,$2)",
                &[&prefixed, &flexible],
            )
            .await?;
        Ok(())
    }

    async fn attempts(&self, key: &str) -> Result<i64> {
        Ok(self.get(key).await?.unwrap_or(0))
    }

    async fn hit(&self, key: &str) -> Result<i64> {
        self.add(&format!("{key}:timer"), epoch() + DECAY_SECONDS)
            .await?;
        let added = self.add(key, 0).await?;
        let hits = self.increment(key).await?;
        if !added && hits == 1 {
            self.put(key, 1).await?;
        }
        Ok(hits)
    }

    async fn too_many_attempts(&self, key: &str) -> Result<bool> {
        if self.attempts(key).await? >= MAX_ATTEMPTS {
            if self.get(&format!("{key}:timer")).await?.is_some() {
                return Ok(true);
            }
            self.forget(key).await?;
        }
        Ok(false)
    }

    async fn available_in(&self, key: &str) -> Result<i64> {
        let timer = self.get(&format!("{key}:timer")).await?.unwrap_or(0);
        Ok((timer - epoch()).max(0))
    }

    async fn clear(&self, key: &str) -> Result<()> {
        self.forget(key).await?;
        self.forget(&format!("{key}:timer")).await
    }
}
fn guard_key() -> &'static str {
    "login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d"
}
pub fn url_decode(value: &str) -> String {
    url::form_urlencoded::parse(format!("x={}", value.replace('+', "%2B")).as_bytes())
        .next()
        .map(|(_, v)| v.into_owned())
        .unwrap_or_default()
}

#[derive(Clone)]
pub struct Context {
    pub membership_id: String,
    pub tenant_id: String,
    pub company_id: Option<String>,
    pub org_id: Option<String>,
    pub permissions: HashSet<String>,
    pub policies: HashMap<String, Vec<Value>>,
}

impl Context {
    pub async fn resolve(
        db: &Client,
        session: &mut Session,
        requested: Option<&Value>,
    ) -> Result<Self> {
        let user = session
            .user
            .as_ref()
            .ok_or_else(|| Error::new(401, "Unauthenticated."))?;
        if flag(user, "must_change_password") {
            return Err(Error::new(403, "Ganti password terlebih dahulu."));
        }
        let user_id = user["id"].as_i64().unwrap();
        let memberships = db::rows(db, "select m.*,row_to_json(t) as tenant from tenant_memberships m join tenants t on t.id=m.tenant_id where m.user_id=$1 and m.status='active' order by m.created_at", &[&user_id]).await?;
        let selected = requested
            .map(|body| text(body, "membership_id"))
            .unwrap_or_else(|| text(&session.data, "workspace.membership_id"));
        let membership = memberships
            .iter()
            .find(|row| text(row, "id") == selected)
            .or_else(|| {
                if requested.is_none() {
                    memberships.first()
                } else {
                    None
                }
            })
            .ok_or_else(|| {
                if requested.is_some() {
                    Error::validation("membership_id", "Keanggotaan tidak sah.")
                } else {
                    Error::new(403, "Tidak ada tenant aktif untuk permintaan ini.")
                }
            })?;
        let membership_id = text(membership, "id");
        let tenant_id = text(membership, "tenant_id");
        let scopes = db::rows(db, "select s.policy_code,s.legal_entity_id,s.organization_id,s.hierarchy_version_id,s.include_descendants from role_assignment_data_policy_scopes s join role_assignments a on a.id=s.role_assignment_id where s.tenant_id=$1 and a.membership_id=$2 and a.status='active' and a.valid_from<=now() and (a.valid_until is null or a.valid_until>now()) and s.valid_from<=now() and (s.valid_until is null or s.valid_until>now())", &[&tenant_id,&membership_id]).await?;
        let mut policies: HashMap<String, Vec<Value>> = HashMap::new();
        let mut all_organizations = false;
        let mut visible_ids: HashSet<String> = HashSet::new();
        for mut scope in scopes {
            if scope["legal_entity_id"].is_null() && scope["organization_id"].is_null() {
                all_organizations = true;
            }
            if let Some(company) = nullable(&scope, "legal_entity_id") {
                visible_ids.insert(company);
            }
            let mut units = Vec::new();
            if let Some(organization) = nullable(&scope, "organization_id") {
                units.push(organization.clone());
                if flag(&scope, "include_descendants") {
                    let version = nullable(&scope, "hierarchy_version_id");
                    for row in db::rows(db,"select descendant_organization_id as id from organization_hierarchy_closures where version_id=$1 and ancestor_organization_id=$2", &[&version,&organization]).await? {units.push(text(&row,"id"));}
                }
            }
            let actual_units=db::rows(db,"select id from organizations where tenant_id=$1 and classification='operating_unit' and id::text=any($2)",&[&tenant_id,&units]).await?;
            let units: Vec<String> = actual_units.iter().map(|row| text(row, "id")).collect();
            visible_ids.extend(units.iter().cloned());
            scope["operating_unit_ids"] = json!(units);
            policies
                .entry(text(&scope, "policy_code"))
                .or_default()
                .push(scope);
        }
        let organizations = db::rows(
            db,
            "select * from organizations where tenant_id=$1 and status='active' order by name",
            &[&tenant_id],
        )
        .await?;
        let allowed: Vec<&Value> = organizations
            .iter()
            .filter(|row| all_organizations || visible_ids.contains(&text(row, "id")))
            .collect();
        let select = |classification: &str, key: &str| -> Result<Option<String>> {
            let desired = requested
                .map(|body| nullable(body, key))
                .unwrap_or_else(|| nullable(&session.data, &format!("workspace.{key}")));
            let selected = allowed.iter().find(|row| {
                text(row, "classification") == classification && Some(text(row, "id")) == desired
            });
            if requested.is_some() && desired.is_some() && selected.is_none() {
                return Err(Error::validation(key, "Organisasi tidak dapat dipilih."));
            }
            Ok(selected
                .or_else(|| {
                    allowed
                        .iter()
                        .find(|row| text(row, "classification") == classification)
                })
                .map(|row| text(row, "id")))
        };
        let company_id = select("legal_entity", "legal_entity_id")?;
        let org_id = select("operating_unit", "org_unit_id")?;
        session.data["workspace.membership_id"] = json!(membership_id);
        session.data["workspace.legal_entity_id"] = json!(company_id);
        session.data["workspace.org_unit_id"] = json!(org_id);
        let permissions=db::rows(db,r#"with recursive reachable(role_id) as (
            select r.id from role_assignments a join roles r on r.id=a.role_id where a.membership_id=$1 and r.tenant_id=$2 and a.status='active' and r.is_active and a.valid_from<=now() and (a.valid_until is null or a.valid_until>now())
            union select link.child_role_id from security_role_children link join reachable on reachable.role_id=link.parent_role_id join roles child on child.id=link.child_role_id and child.tenant_id=$2 and child.is_active where link.tenant_id=$2)
            select distinct p.code from reachable r join security_role_duties rd on rd.role_id=r.role_id join security_duty_privileges dp on dp.duty_code=rd.duty_code join security_privilege_permissions pp on pp.privilege_code=dp.privilege_code join permissions p on p.code=pp.permission_code where p.app_id='management-aset' order by p.code"#,&[&membership_id,&tenant_id]).await?.iter().map(|row|text(row,"code")).collect();
        Ok(Self {
            membership_id,
            tenant_id,
            company_id,
            org_id,
            permissions,
            policies,
        })
    }
    pub fn require(&self, permission: &str) -> Result<()> {
        if self.permissions.contains(permission) {
            Ok(())
        } else {
            Err(Error::new(403, "Forbidden"))
        }
    }
    pub fn allows(&self, company: &str, unit: Option<&str>) -> bool {
        if self.company_id.as_deref() != Some(company) {
            return false;
        }
        self.policies
            .get("management-aset.asset-responsibility")
            .is_some_and(|scopes| {
                scopes.iter().any(|scope| {
                    if scope["legal_entity_id"].is_null() && scope["organization_id"].is_null() {
                        return true;
                    }
                    let company_allowed = !scope["legal_entity_id"].is_null()
                        && text(scope, "legal_entity_id") == company;
                    let units = scope["operating_unit_ids"].as_array();
                    let unit_allowed = unit.is_some_and(|unit| {
                        units.is_some_and(|units| {
                            units.iter().any(|value| value.as_str() == Some(unit))
                        })
                    });
                    company_allowed && unit_allowed
                })
            })
    }
    pub fn assert_row(&self, row: &Value) -> Result<()> {
        if text(row, "tenant_id") != self.tenant_id
            || !self.allows(
                &text(row, "legal_entity_id"),
                nullable(row, "responsible_org_unit_id").as_deref(),
            )
        {
            return Err(Error::new(404, "Not Found"));
        }
        Ok(())
    }
    pub fn require_scope(&self, company: &str, unit: Option<&str>) -> Result<()> {
        if self.company_id.is_none() {
            return Err(Error::validation(
                "legal_entity_id",
                "Pilih perusahaan di tempat kerja sebelum melanjutkan.",
            ));
        }
        if self.company_id.as_deref() != Some(company) {
            return Err(Error::validation(
                "legal_entity_id",
                "Perusahaan transaksi harus sama dengan perusahaan aktif. Ganti tempat kerja terlebih dahulu.",
            ));
        }
        if !self.allows(company, unit) {
            return Err(Error::new(
                403,
                "Data ini berada di luar unit kerja yang dapat Anda akses.",
            ));
        }
        Ok(())
    }
}
