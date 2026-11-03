use axum::{
    Json,
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
};
use serde_json::{Value, json};
use tokio_postgres::{Row, types::ToSql};

pub type Result<T> = std::result::Result<T, Error>;
pub type Params<'a> = &'a [&'a (dyn ToSql + Sync)];
/// Koneksi pool. Statement disimpan per koneksi seperti pgx dan postgres.js prepare; tanpa cache setiap
/// query di-parse dan di-plan ulang (A/B Bun 200 VU: CPU database per request turun 49%).
pub type Client = deadpool_postgres::ClientWrapper;

#[allow(async_fn_in_trait)]
pub trait Cached {
    async fn cached_execute(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<u64, tokio_postgres::Error>;
    async fn cached_query(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<Vec<Row>, tokio_postgres::Error>;
    async fn cached_query_one(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<Row, tokio_postgres::Error>;
    async fn cached_query_opt(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<Option<Row>, tokio_postgres::Error>;
}

impl Cached for Client {
    async fn cached_execute(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<u64, tokio_postgres::Error> {
        let statement = self.prepare_cached(sql).await?;
        self.execute(&statement, params).await
    }
    async fn cached_query(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<Vec<Row>, tokio_postgres::Error> {
        let statement = self.prepare_cached(sql).await?;
        self.query(&statement, params).await
    }
    async fn cached_query_one(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<Row, tokio_postgres::Error> {
        let statement = self.prepare_cached(sql).await?;
        self.query_one(&statement, params).await
    }
    async fn cached_query_opt(
        &self,
        sql: &str,
        params: Params<'_>,
    ) -> std::result::Result<Option<Row>, tokio_postgres::Error> {
        let statement = self.prepare_cached(sql).await?;
        self.query_opt(&statement, params).await
    }
}

#[derive(Debug)]
pub struct Error {
    pub status: StatusCode,
    pub body: Value,
    pub sql_state: Option<String>,
    pub headers: HeaderMap,
}

impl Error {
    pub fn new(status: u16, message: &str) -> Self {
        Self {
            status: StatusCode::from_u16(status).unwrap(),
            body: json!({"message": message}),
            sql_state: None,
            headers: HeaderMap::new(),
        }
    }
    pub fn validation(field: &str, message: &str) -> Self {
        let mut error = Self::new(422, message);
        error.body["errors"] = json!({field: [message]});
        error
    }
    pub fn coded(status: u16, code: &str, message: &str) -> Self {
        let mut error = Self::new(status, message);
        error.body = json!({"error":{"code":code,"message":message}});
        error
    }
    pub fn unsupported(message: &str) -> Self {
        Self::coded(422, "unsupported_profile", message)
    }
}

impl From<tokio_postgres::Error> for Error {
    fn from(error: tokio_postgres::Error) -> Self {
        if let Some(detail) = error.as_db_error() {
            eprintln!(
                "database error {}: {}",
                detail.code().code(),
                detail.message()
            );
        } else {
            eprintln!("database connection error: {error}");
        }
        let mut result = Self::new(500, "Server Error");
        result.sql_state = error.code().map(|code| code.code().to_owned());
        result
    }
}

impl IntoResponse for Error {
    fn into_response(self) -> Response {
        (self.status, self.headers, Json(self.body)).into_response()
    }
}

pub async fn rows(db: &Client, sql: &str, params: Params<'_>) -> Result<Vec<Value>> {
    let statement = format!("select row_to_json(result) from ({sql}) result");
    Ok(db
        .cached_query(&statement, params)
        .await?
        .into_iter()
        .map(|row| row.get(0))
        .collect())
}

pub async fn optional(db: &Client, sql: &str, params: Params<'_>) -> Result<Option<Value>> {
    Ok(rows(db, sql, params).await?.into_iter().next())
}

pub async fn one(db: &Client, sql: &str, params: Params<'_>) -> Result<Value> {
    optional(db, sql, params)
        .await?
        .ok_or_else(|| Error::new(404, "Not Found"))
}

pub async fn insert(db: &Client, table: &str, mut data: Value, timestamps: bool) -> Result<Value> {
    let object = data.as_object_mut().expect("internal insert record");
    if timestamps {
        let now = now();
        object.insert("created_at".into(), json!(now));
        object.insert("updated_at".into(), json!(now));
    }
    let fields = object
        .keys()
        .map(|key| format!("\"{key}\""))
        .collect::<Vec<_>>()
        .join(",");
    let sql = format!(
        "insert into {table} ({fields}) select {fields} from json_populate_record(null::{table}, $1) returning row_to_json({table})"
    );
    Ok(db.cached_query_one(&sql, &[&data]).await?.get(0))
}

pub fn now() -> String {
    chrono::Utc::now().format("%Y-%m-%d %H:%M:%S").to_string()
}
pub fn id() -> String {
    ulid::Ulid::new().to_string().to_lowercase()
}
pub fn text(value: &Value, key: &str) -> String {
    match &value[key] {
        Value::String(s) => s.clone(),
        Value::Number(n) => n.to_string(),
        _ => String::new(),
    }
}
pub fn nullable(value: &Value, key: &str) -> Option<String> {
    if value[key].is_null() {
        None
    } else {
        Some(text(value, key))
    }
}
pub fn number(value: &Value, key: &str) -> i64 {
    value[key].as_i64().unwrap_or(0)
}
pub fn flag(value: &Value, key: &str) -> bool {
    value[key].as_bool().unwrap_or(false)
}
