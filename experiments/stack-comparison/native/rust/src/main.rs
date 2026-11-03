mod auth;
mod crypto;
mod db;
mod lists;
mod money;
mod numbering;
mod posting;
mod receipts;

use axum::{
    Json, Router,
    body::{Body, to_bytes},
    extract::{ConnectInfo, Request, State},
    http::{HeaderValue, Method, StatusCode},
    response::{Html, IntoResponse, Response},
};
use deadpool_postgres::{Manager, ManagerConfig, Pool, RecyclingMethod};
use serde_json::{Value, json};
use std::{collections::HashMap, net::SocketAddr, sync::Arc};
use tokio_postgres::NoTls;

pub struct App {
    pub pool: Pool,
    pub crypto: crypto::CookieCrypto,
    pub cookie_name: String,
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let mut database = tokio_postgres::Config::new();
    database
        .host(&env("DB_HOST", "127.0.0.1"))
        .port(env("DB_PORT", "18640").parse()?)
        .user(&env("DB_USERNAME", "compare_admin"))
        .password(std::env::var("DB_PASSWORD")?)
        .dbname(&env("DB_DATABASE", "erp_compare_rust"))
        .application_name("coreerp-native-rust");
    let manager = Manager::from_config(
        database,
        NoTls,
        ManagerConfig {
            recycling_method: RecyclingMethod::Custom("ROLLBACK; RESET ALL".into()),
        },
    );
    let pool = Pool::builder(manager)
        .max_size(env("DB_POOL", "8").parse()?)
        .build()?;
    let crypto =
        crypto::CookieCrypto::new(&std::env::var("APP_KEY")?).map_err(std::io::Error::other)?;
    let state = Arc::new(App {
        pool,
        crypto,
        cookie_name: env("SESSION_COOKIE", "laravel-session"),
    });
    let address = format!("{}:{}", env("BIND_HOST", "127.0.0.1"), env("PORT", "18643"));
    let listener = tokio::net::TcpListener::bind(&address).await?;
    eprintln!("Native Rust comparison listening on {address}");
    axum::serve(
        listener,
        Router::new()
            .fallback(handle)
            .with_state(state)
            .into_make_service_with_connect_info::<SocketAddr>(),
    )
    .await?;
    Ok(())
}
fn env(name: &str, fallback: &str) -> String {
    std::env::var(name).unwrap_or_else(|_| fallback.to_owned())
}

async fn handle(
    State(app): State<Arc<App>>,
    ConnectInfo(address): ConnectInfo<SocketAddr>,
    request: Request<Body>,
) -> Response {
    let (parts, body) = request.into_parts();
    let body = match to_bytes(body, 2 * 1024 * 1024).await {
        Ok(bytes) => bytes,
        Err(_) => return db::Error::new(413, "Payload Too Large").into_response(),
    };
    let body: Value = if body.is_empty() {
        json!({})
    } else {
        match serde_json::from_slice(&body) {
            Ok(value) => value,
            Err(_) => return db::Error::new(400, "Invalid JSON").into_response(),
        }
    };
    let connection = match app.pool.get().await {
        Ok(db) => db,
        Err(error) => {
            eprintln!("pool acquisition failed: {error}");
            return db::Error::new(503, "Service Unavailable").into_response();
        }
    };
    let db: &tokio_postgres::Client = &connection;
    let mut session = match auth::Session::load(&app, db, &parts.headers).await {
        Ok(session) => session,
        Err(error) => return error.into_response(),
    };
    let ip = address.ip().to_string();
    let output=async {
        session.csrf(&app,&parts.method,&parts.headers,&body)?;
        let path=parts.uri.path();
        if path=="/health" && parts.method==Method::GET {db.simple_query("select 1").await?;return Ok(Json(json!({"status":"ok","runtime":"rust"})).into_response());}
        if path=="/login" {
            if parts.method==Method::GET {
                if parts.headers.get("x-inertia").is_some_and(|value|value=="true") {
                    let host=parts.headers.get("host").and_then(|value|value.to_str().ok()).unwrap_or("127.0.0.1");
                    return Ok((StatusCode::CONFLICT,[("x-inertia-location",format!("http://{host}/login"))]).into_response());
                }
                return Ok(Html("<!doctype html><html><head><title>Login</title></head><body><form method=\"post\" action=\"/login\"><input name=\"email\" type=\"email\"><input name=\"password\" type=\"password\"><button>Login</button></form></body></html>").into_response());
            }
            if parts.method==Method::POST {return Ok(Json(session.login(db,&body,&ip).await?).into_response());}
        }
        if path=="/logout" && parts.method==Method::POST {
            db.execute("delete from sessions where id=$1",&[&session.id]).await?;
            session.id=crypto::random_token();session.user=None;session.data=json!({"_token":crypto::random_token()});
            return Ok(StatusCode::NO_CONTENT.into_response());
        }
        if path=="/api/v1/workspace-context" && parts.method==Method::PUT {
            let context=auth::Context::resolve(db,&mut session,Some(&body)).await?;
            return Ok(Json(json!({"data":{"membership_id":context.membership_id,"tenant_id":context.tenant_id,"legal_entity_id":context.company_id,"org_unit_id":context.org_id}})).into_response());
        }
        let prefix="/api/modules/management-aset/v1";
        if let Some(path)=path.strip_prefix(prefix) {
            let context=auth::Context::resolve(db,&mut session,None).await?;
            if context.permissions.is_empty(){return Err(db::Error::new(403,"Tidak ada izin untuk module ini."));}
            let mut query:HashMap<String,String>=HashMap::new();
            let mut record_ids=Vec::new();
            for (key,value) in url::form_urlencoded::parse(parts.uri.query().unwrap_or("").as_bytes()).into_owned(){
                if key.starts_with("record_ids["){record_ids.push(value);}else{query.insert(key,value);}
            }
            if !record_ids.is_empty(){query.insert("record_ids".into(),serde_json::to_string(&record_ids).unwrap());}
            return receipts::route(db,&context,&parts.method,path,&query,&parts.headers,&body).await;
        }
        Err(db::Error::new(404,"Not Found"))
    }.await;
    let mut response = match output {
        Ok(response) => response,
        Err(error) => {
            let _ = db.batch_execute("ROLLBACK").await;
            error.into_response()
        }
    };
    if let Err(error) = session.save(db, &parts.headers, &ip).await {
        response = error.into_response();
    }
    if let Err(error) = db.batch_execute("ROLLBACK; RESET ALL").await {
        eprintln!("connection cleanup failed: {error}");
    }
    for (name, value, http_only) in [
        (app.cookie_name.as_str(), session.id.as_str(), true),
        (
            "XSRF-TOKEN",
            session.data["_token"].as_str().unwrap_or(""),
            false,
        ),
    ] {
        let value = app.crypto.encrypt(name, value);
        let value: String = url::form_urlencoded::byte_serialize(value.as_bytes()).collect();
        let cookie = format!(
            "{name}={value}; Path=/; Max-Age=7200; SameSite=Lax{}",
            if http_only { "; HttpOnly" } else { "" }
        );
        if let Ok(header) = HeaderValue::from_str(&cookie) {
            response.headers_mut().append("set-cookie", header);
        }
    }
    response
}
