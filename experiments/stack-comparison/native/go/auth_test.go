package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/redis/go-redis/v9"
)

func TestCookieRejectsTamperingAndWrongCookieName(t *testing.T) {
	key := []byte("0123456789abcdef0123456789abcdef")
	value, e := encryptCookie("laravel-session", "0123456789abcdef0123456789abcdef01234567", key)
	if e != nil {
		t.Fatal(e)
	}
	plain, e := decryptCookie("laravel-session", value, key)
	if e != nil || plain != "0123456789abcdef0123456789abcdef01234567" {
		t.Fatalf("decrypt = %q,%v", plain, e)
	}
	if _, e = decryptCookie("XSRF-TOKEN", value, key); e == nil {
		t.Fatal("cookie value accepted for another name")
	}
	raw, _ := base64.StdEncoding.DecodeString(value)
	var envelope map[string]string
	if e = json.Unmarshal(raw, &envelope); e != nil {
		t.Fatal(e)
	}
	envelope["value"] = "AA" + envelope["value"][2:]
	tampered := base64.StdEncoding.EncodeToString([]byte(jsonText(envelope)))
	if _, e = decryptCookie("laravel-session", tampered, key); e == nil {
		t.Fatal("modified ciphertext accepted")
	}
	if _, e = decryptCookie("laravel-session", value, []byte("abcdef0123456789abcdef0123456789")); e == nil {
		t.Fatal("wrong application key accepted")
	}
}
func TestCSRFRequiresTokenWithoutSameOriginFetchMetadata(t *testing.T) {
	key := []byte("0123456789abcdef0123456789abcdef")
	s := &server{key: key}
	q := &request{server: s, r: httptest.NewRequest("POST", "/login", nil), session: object{"_token": "session-token"}}
	if e := q.csrf(); e == nil {
		t.Fatal("missing token accepted")
	}
	q.r.Header.Set("X-CSRF-TOKEN", "other-token")
	if e := q.csrf(); e == nil {
		t.Fatal("wrong token accepted")
	}
	token, e := encryptCookie("XSRF-TOKEN", "session-token", key)
	if e != nil {
		t.Fatal(e)
	}
	q.r.Header.Del("X-CSRF-TOKEN")
	q.r.Header.Set("X-XSRF-TOKEN", token)
	if e = q.csrf(); e != nil {
		t.Fatal(e)
	}
}
func TestVersionHeaderHasPrecedenceAndZeroIsAStaleCandidate(t *testing.T) {
	q := &request{r: httptest.NewRequest("POST", "/", nil)}
	q.r.Header.Set("If-Match", " W/\"3\" ")
	v, e := q.expectedVersion(object{"version": json.Number("9")})
	if e != nil || v != 3 {
		t.Fatalf("version=%d,%v", v, e)
	}
	q.r.Header.Set("If-Match", "wrong")
	if _, e = q.expectedVersion(object{"version": json.Number("9")}); e == nil {
		t.Fatal("malformed header fell back to body")
	}
	q.r.Header.Del("If-Match")
	v, e = q.expectedVersion(object{"version": json.Number("0")})
	if e != nil || v != 0 {
		t.Fatalf("zero version=%d,%v", v, e)
	}
	if _, e = q.expectedVersion(object{"version": json.Number("1.0")}); e == nil {
		t.Fatal("JSON float accepted as integer version")
	}
}
func TestDetailObjectsKeepRequestOrder(t *testing.T) {
	q := &request{r: httptest.NewRequest("POST", "/", strings.NewReader(`{"details":{"z":{"nama":" first "},"a":{"nama":"second"}}}`))}
	b, e := q.body()
	if e != nil {
		t.Fatal(e)
	}
	details := objects(b["details"])
	if len(details) != 2 || str(details[0]["nama"]) != "first" || str(details[1]["nama"]) != "second" {
		t.Fatalf("details=%v", details)
	}
}
func TestValkeyFailureIsServiceUnavailableWithoutRewritingSession(t *testing.T) {
	// Server palsu yang menutup setiap koneksi: setiap perintah Valkey gagal tanpa menunggu timeout.
	listener, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		t.Fatal(e)
	}
	defer listener.Close()
	go func() {
		for {
			conn, e := listener.Accept()
			if e != nil {
				return
			}
			conn.Close()
		}
	}()
	client := redis.NewClient(&redis.Options{Addr: listener.Addr().String(), MaxRetries: -1, DialerRetries: 1})
	defer client.Close()
	key := []byte("0123456789abcdef0123456789abcdef")
	s := &server{key: key, cookie: "laravel-session", lifetime: 7200, valkey: client, sessionPrefix: "coreerp-session:"}
	value, e := encryptCookie("laravel-session", strings.Repeat("a", 40), key)
	if e != nil {
		t.Fatal(e)
	}
	r := httptest.NewRequest("GET", "/api/modules/management-aset/v1/aset", nil)
	r.AddCookie(&http.Cookie{Name: "laravel-session", Value: url.QueryEscape(value)})
	q := &request{server: s, ctx: context.Background(), r: r}
	var f *failure
	if e = q.loadSession(); !errors.As(e, &f) || f.status != 503 {
		t.Fatalf("load error=%v", e)
	}
	if q.session != nil {
		t.Fatal("unreadable session would be overwritten at the end of the request")
	}
	q.session = object{"_token": "token"}
	w := httptest.NewRecorder()
	if e = q.saveSession(w); !errors.As(e, &f) || f.status != 503 {
		t.Fatalf("save error=%v", e)
	}
	if cookies := w.Result().Cookies(); len(cookies) != 0 {
		t.Fatalf("cookies set after failed write: %v", cookies)
	}
}
