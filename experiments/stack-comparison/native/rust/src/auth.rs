use crate::{
    App,
    crypto::{constant_eq, random_token},
    db::{self, Error, Result, flag, nullable, text},
};
use axum::http::{HeaderMap, Method};
use base64::{Engine, engine::general_purpose::STANDARD};
use rand::Rng;
use serde_json::{Value, json};
use sha1::{Digest, Sha1};
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

    pub async fn login(&mut self, db: &Client, body: &Value, ip: &str) -> Result<Value> {
        let email = text(body, "email").to_lowercase();
        let password = text(body, "password");
        if email.is_empty() || password.is_empty() {
            return Err(Error::validation("email", "The email field is required."));
        }
        let limiter_key = format!(
            "native-rust:login:{}",
            hex::encode(Sha1::digest(format!("{email}|{ip}").as_bytes()))
        );
        let epoch = chrono::Utc::now().timestamp();
        db.execute("insert into cache (key,value,expiration) values ($1,'i:0;',$2) on conflict (key) do nothing", &[&limiter_key, &(epoch + 60)]).await?;
        let attempts = db.query_one("update cache set value='i:' || (case when expiration <= $2 then 1 else cast(substring(value from '[0-9]+') as integer)+1 end)::text || ';', expiration=case when expiration <= $2 then $2+60 else expiration end where key=$1 returning value", &[&limiter_key, &epoch]).await?;
        let count: i32 = attempts
            .get::<_, String>(0)
            .trim_start_matches("i:")
            .trim_end_matches(';')
            .parse()
            .unwrap_or(6);
        if count > 5 {
            return Err(Error::new(429, "Too Many Attempts."));
        }
        let user = db::optional(
            db,
            "select * from users where lower(email)=$1 and account_type='person' limit 1",
            &[&email],
        )
        .await?;
        let Some(user) = user else {
            return Err(login_failed());
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
            return Err(login_failed());
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
            return Err(login_failed());
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
        db.execute("delete from cache where key=$1", &[&limiter_key])
            .await?;
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

fn login_failed() -> Error {
    Error::validation("email", "These credentials do not match our records.")
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
