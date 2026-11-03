package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgtype"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/oklog/ulid/v2"
	"github.com/shopspring/decimal"
)

type object map[string]any

type database interface {
	Query(context.Context, string, ...any) (pgx.Rows, error)
	QueryRow(context.Context, string, ...any) pgx.Row
	Exec(context.Context, string, ...any) (pgconn.CommandTag, error)
}

type server struct {
	pool     *pgxpool.Pool
	key      []byte
	cookie   string
	lifetime int64
}

type request struct {
	*server
	ctx           context.Context
	conn          *pgxpool.Conn
	db            database
	r             *http.Request
	session       object
	sessionID     string
	user          object
	authority     *authority
	payload       object
	payloadParsed bool
	payloadError  error
}

type response struct {
	status  int
	body    any
	headers http.Header
}

type failure struct {
	status  int
	message string
	code    string
	errors  object
	headers http.Header
}

func (e *failure) Error() string            { return e.message }
func fail(status int, message string) error { return &failure{status: status, message: message} }
func invalid(field, message string) error {
	return &failure{status: 422, message: message, errors: object{field: []string{message}}}
}
func unsupported(message string) error {
	return &failure{status: 422, message: message, code: "comparison_scope_not_supported"}
}

func main() {
	key, err := applicationKey(os.Getenv("APP_KEY"))
	if err != nil {
		log.Fatal(err)
	}
	dsn := os.Getenv("DATABASE_URL")
	if dsn == "" {
		dsn = fmt.Sprintf("host=%s port=%s user=%s password=%s dbname=%s sslmode=disable application_name=stack-native-go", env("DB_HOST", "127.0.0.1"), env("DB_PORT", "18640"), env("DB_USERNAME", "postgres"), os.Getenv("DB_PASSWORD"), env("DB_DATABASE", "erp_compare_go"))
	}
	config, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		log.Fatal("invalid database configuration")
	}
	config.MaxConns = int32(integer(env("DB_POOL_SIZE", "10")))
	config.ConnConfig.RuntimeParams["timezone"] = "UTC"
	pool, err := pgxpool.NewWithConfig(context.Background(), config)
	if err != nil {
		log.Fatal("database pool initialization failed")
	}
	defer pool.Close()
	if env("SESSION_DRIVER", "database") != "database" || env("SESSION_ENCRYPT", "false") != "false" {
		log.Fatal("this comparison requires unencrypted database JSON sessions")
	}
	s := &server{pool: pool, key: key, cookie: env("SESSION_COOKIE", "laravel-session"), lifetime: integer(env("SESSION_LIFETIME", "120")) * 60}
	h := &http.Server{Addr: env("LISTEN_ADDR", "127.0.0.1:18642"), Handler: s, ReadHeaderTimeout: 10 * time.Second}
	log.Printf("native Go HTTP listening on %s", h.Addr)
	log.Fatal(h.ListenAndServe())
}

func env(name, fallback string) string {
	if s := os.Getenv(name); s != "" {
		return s
	}
	return fallback
}
func integer(v any) int64 { n, _ := strconv.ParseInt(str(v), 10, 64); return n }
func str(v any) string {
	if v == nil {
		return ""
	}
	return fmt.Sprint(v)
}
func boolean(v any) bool    { b, _ := v.(bool); return b }
func uid() string           { return ulid.Make().String() }
func lowerUID() string      { return strings.ToLower(uid()) }
func jsonText(v any) string { b, _ := json.Marshal(v); return string(b) }
func decode(b []byte, v any) error {
	d := json.NewDecoder(bytes.NewReader(b))
	d.UseNumber()
	return d.Decode(v)
}
func jsonObject(v any) object {
	if m, ok := v.(map[string]any); ok {
		return object(m)
	}
	if m, ok := v.(object); ok {
		return m
	}
	return object{}
}
func objects(v any) []object {
	a := []object{}
	switch rows := v.(type) {
	case []any:
		for _, r := range rows {
			a = append(a, jsonObject(r))
		}
	case []object:
		return rows
	case map[string]any:
		for _, r := range rows {
			a = append(a, jsonObject(r))
		}
	}
	return a
}
func nullable(v any) any {
	if v == nil || v == "" {
		return nil
	}
	return v
}
func take(m object, keys ...string) object {
	n := object{}
	for _, k := range keys {
		n[k] = m[k]
	}
	return n
}

func rows(ctx context.Context, db database, sql string, args ...any) ([]object, error) {
	r, err := db.Query(ctx, sql, args...)
	if err != nil {
		return nil, err
	}
	defer r.Close()
	result := []object{}
	for r.Next() {
		v, err := r.Values()
		if err != nil {
			return nil, err
		}
		m := object{}
		for i, f := range r.FieldDescriptions() {
			m[string(f.Name)] = dbValue(v[i], f.DataTypeOID, f.TypeModifier)
		}
		result = append(result, m)
	}
	return result, r.Err()
}
func row(ctx context.Context, db database, sql string, args ...any) (object, error) {
	r, err := rows(ctx, db, sql, args...)
	if err != nil {
		return nil, err
	}
	if len(r) == 0 {
		return nil, pgx.ErrNoRows
	}
	return r[0], nil
}
func dbValue(v any, oid uint32, modifier int32) any {
	if v == nil {
		return nil
	}
	if t, ok := v.(time.Time); ok {
		if oid == 1082 {
			return t.Format("2006-01-02")
		}
		return t.UTC().Format("2006-01-02 15:04:05")
	}
	if oid == 1700 {
		if n, ok := v.(pgtype.Numeric); ok && n.Valid && n.Int != nil {
			scale := max(0, -n.Exp)
			if modifier >= 4 {
				scale = max(0, int32(int16((modifier-4)&0xffff)))
			}
			return decimal.NewFromBigInt(n.Int, n.Exp).StringFixed(scale)
		}
		b, err := json.Marshal(v)
		if err == nil {
			return strings.Trim(string(b), "\"")
		}
	}
	return v
}
func (q *request) rows(sql string, args ...any) ([]object, error) {
	return rows(q.ctx, q.db, sql, args...)
}
func (q *request) row(sql string, args ...any) (object, error) { return row(q.ctx, q.db, sql, args...) }
func (q *request) exec(sql string, args ...any) error {
	_, err := q.db.Exec(q.ctx, sql, args...)
	return err
}
func (q *request) transaction(fn func() error) error {
	var tx pgx.Tx
	var err error
	if parent, ok := q.db.(pgx.Tx); ok {
		tx, err = parent.Begin(q.ctx)
	} else {
		tx, err = q.conn.Begin(q.ctx)
	}
	if err != nil {
		return err
	}
	previous := q.db
	q.db = tx
	defer func() { q.db = previous; _ = tx.Rollback(q.ctx) }()
	if err = fn(); err != nil {
		return err
	}
	return tx.Commit(q.ctx)
}
func (s *server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), 60*time.Second)
	defer cancel()
	conn, err := s.pool.Acquire(ctx)
	if err != nil {
		http.Error(w, "Database tidak tersedia.", 503)
		return
	}
	defer conn.Release()
	q := &request{server: s, ctx: ctx, conn: conn, db: conn, r: r}
	defer func() { _, _ = conn.Exec(context.Background(), "select set_config('coreerp.user_id','',false)") }()
	if err = q.loadSession(); err == nil {
		err = q.csrf()
	}
	var res response
	if err == nil {
		res, err = q.dispatch()
	}
	if err != nil {
		var f *failure
		if errors.As(err, &f) {
			res = response{status: f.status, body: object{"message": f.message}, headers: f.headers}
			b := res.body.(object)
			if f.errors != nil {
				b["errors"] = f.errors
			}
			if f.code != "" {
				res.body = object{"error": object{"code": f.code, "message": f.message}}
			}
		} else if errors.Is(err, pgx.ErrNoRows) {
			res = response{status: 404, body: object{"message": "Not Found"}}
		} else {
			log.Printf("request failed %s %s: %v", r.Method, r.URL.Path, err)
			res = response{status: 500, body: object{"message": "Server Error"}}
		}
	}
	if q.session != nil {
		if saveErr := q.saveSession(w); saveErr != nil {
			log.Printf("session write failed: %v", saveErr)
			res = response{status: 500, body: object{"message": "Server Error"}}
		}
	}
	for k, values := range res.headers {
		for _, v := range values {
			w.Header().Add(k, v)
		}
	}
	w.Header().Set("Content-Type", "application/json")
	if res.status == 0 {
		res.status = 200
	}
	w.WriteHeader(res.status)
	if res.status != 204 {
		_ = json.NewEncoder(w).Encode(res.body)
	}
}
func (q *request) body() (object, error) {
	if !q.payloadParsed {
		q.payloadParsed = true
		q.payload, q.payloadError = q.parseBody()
	}
	return q.payload, q.payloadError
}
func (q *request) parseBody() (object, error) {
	m := object{}
	raw, err := io.ReadAll(io.LimitReader(q.r.Body, (2<<20)+1))
	if err != nil || len(raw) > 2<<20 {
		return nil, invalid("body", "Isi permintaan terlalu besar.")
	}
	if err := decode(raw, &m); err != nil {
		return nil, invalid("body", "Isi permintaan harus JSON.")
	}
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(raw, &fields); err != nil {
		return nil, invalid("body", "Isi permintaan harus JSON.")
	}
	if detail := bytes.TrimSpace(fields["details"]); len(detail) > 0 && detail[0] == '{' {
		d := json.NewDecoder(bytes.NewReader(detail))
		d.UseNumber()
		_, _ = d.Token()
		ordered := []object{}
		for d.More() {
			if _, err := d.Token(); err != nil {
				return nil, invalid("details", "Baris tidak valid.")
			}
			var value object
			if err := d.Decode(&value); err != nil {
				return nil, invalid("details", "Baris tidak valid.")
			}
			ordered = append(ordered, value)
		}
		m["details"] = ordered
	}
	trimInput(m, "")
	return m, nil
}
func trimInput(v any, key string) {
	switch n := v.(type) {
	case object:
		for k, value := range n {
			if text, ok := value.(string); ok && k != "password" {
				n[k] = nullable(strings.TrimSpace(text))
			} else {
				trimInput(value, k)
			}
		}
	case map[string]any:
		trimInput(object(n), key)
	case []any:
		for _, value := range n {
			trimInput(value, key)
		}
	case []object:
		for _, value := range n {
			trimInput(value, key)
		}
	}
}
func (q *request) dispatch() (response, error) {
	p := q.r.URL.Path
	m := q.r.Method
	if m == "GET" && p == "/login" {
		return response{body: object{}}, nil
	}
	if m == "POST" && p == "/login" {
		return q.login()
	}
	if err := q.restoreUser(); err != nil {
		return response{}, err
	}
	if m == "POST" && p == "/logout" {
		return q.logout()
	}
	if m == "PUT" && p == "/api/v1/workspace-context" {
		return q.workspace()
	}
	if !strings.HasPrefix(p, "/api/modules/management-aset/v1/") {
		return response{}, fail(404, "Not Found")
	}
	if err := q.resolveAuthority(""); err != nil {
		return response{}, err
	}
	rel := strings.TrimPrefix(p, "/api/modules/management-aset/v1/")
	parts := strings.Split(rel, "/")
	if parts[0] == "penerimaan-aset" {
		return q.receiptRoute(parts[1:])
	}
	if parts[0] == "aset" {
		return q.assetRoute(parts[1:])
	}
	return response{}, fail(404, "Not Found")
}
