package main

import (
	"fmt"
	"net/http/httptest"
	"sync"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
)

func TestContextCacheVersionChangeClearsEverything(t *testing.T) {
	c := newContextCache(time.Minute)
	now := time.Now()
	c.observe(1)
	c.put(1, "user:1", object{"id": "1"}, now.Add(time.Minute))
	if _, ok := c.get(1, "user:1", now); !ok {
		t.Fatal("entry for the current version missed")
	}
	c.observe(2)
	if len(c.entries) != 0 {
		t.Fatalf("entries after version change = %d", len(c.entries))
	}
	// Request yang membaca versi lama sebelum perubahan tidak boleh mengisi atau membaca cache versi baru.
	c.put(1, "user:1", object{"id": "1"}, now.Add(time.Minute))
	if len(c.entries) != 0 {
		t.Fatal("stale version wrote into the new cache")
	}
	c.put(2, "user:2", object{"id": "2"}, now.Add(time.Minute))
	if _, ok := c.get(1, "user:2", now); ok {
		t.Fatal("request with an older version read the new cache")
	}
	if _, ok := c.get(2, "user:2", now); !ok {
		t.Fatal("entry for the new version missed")
	}
}
func TestCachedContextReadsBoundaryBeforeDataAndExpiresAtIt(t *testing.T) {
	c := newContextCache(5 * time.Minute)
	c.observe(3)
	q := &request{server: &server{contextCache: c}, cacheVersion: 3}
	order := []string{}
	boundary := time.Now().Add(time.Second)
	limit := func() (*time.Time, error) { order = append(order, "boundary"); return &boundary, nil }
	load := func() (string, error) { order = append(order, "data"); return "value", nil }
	if v, e := cachedContext(q, "permissions:m1|t1", limit, load); e != nil || v != "value" {
		t.Fatalf("value=%q,%v", v, e)
	}
	if fmt.Sprint(order) != "[boundary data]" {
		t.Fatalf("order=%v", order)
	}
	if v, e := cachedContext(q, "permissions:m1|t1", limit, load); e != nil || v != "value" || len(order) != 2 {
		t.Fatalf("hit reloaded: value=%q,%v order=%v", v, e, order)
	}
	if got := c.entries["permissions:m1|t1"].expires; !got.Equal(boundary) {
		t.Fatalf("expires=%v, want boundary %v", got, boundary)
	}
	if _, ok := c.get(3, "permissions:m1|t1", boundary.Add(-time.Nanosecond)); !ok {
		t.Fatal("entry missed before its validity boundary")
	}
	if _, ok := c.get(3, "permissions:m1|t1", boundary); ok {
		t.Fatal("entry served at its validity boundary")
	}
	now := time.Now()
	if got := c.expiry(now, nil); !got.Equal(now.Add(5 * time.Minute)) {
		t.Fatalf("expiry without boundary=%v", got)
	}
	late := now.Add(time.Hour)
	if got := c.expiry(now, &late); !got.Equal(now.Add(5 * time.Minute)) {
		t.Fatalf("expiry with a boundary past the TTL=%v", got)
	}
}
func TestCachedContextDoesNotStoreFailures(t *testing.T) {
	c := newContextCache(time.Minute)
	c.observe(1)
	q := &request{server: &server{contextCache: c}, cacheVersion: 1}
	if _, e := cachedContext(q, "user:9", nil, func() (object, error) { return nil, pgx.ErrNoRows }); e != pgx.ErrNoRows {
		t.Fatalf("error=%v", e)
	}
	if len(c.entries) != 0 {
		t.Fatal("failed read was cached")
	}
}
func TestContextCacheIsBounded(t *testing.T) {
	c := newContextCache(time.Minute)
	c.observe(1)
	expires := time.Now().Add(time.Minute)
	for i := range contextCacheLimit {
		c.put(1, fmt.Sprint(i), i, expires)
	}
	if len(c.entries) != contextCacheLimit {
		t.Fatalf("entries=%d", len(c.entries))
	}
	c.put(1, "overflow", 0, expires)
	if len(c.entries) != 1 {
		t.Fatalf("entries after overflow=%d", len(c.entries))
	}
}
func TestContextCacheConcurrentAccess(t *testing.T) {
	c := newContextCache(time.Minute)
	var wg sync.WaitGroup
	for g := range 8 {
		wg.Go(func() {
			for i := range 2000 {
				version := int64(i / 500)
				c.observe(version)
				key := fmt.Sprint("user:", (g*31+i)%50)
				if _, ok := c.get(version, key, time.Now()); !ok {
					c.put(version, key, object{"id": key}, time.Now().Add(time.Minute))
				}
			}
		})
	}
	wg.Wait()
}
func TestWorkspaceSelectionIsComputedPerRequestFromCachedContext(t *testing.T) {
	c := newContextCache(time.Minute)
	c.observe(7)
	expires := time.Now().Add(time.Minute)
	c.put(7, "memberships:u1", []object{{"id": "m1", "tenant_id": "t1"}}, expires)
	c.put(7, "permissions:m1|t1", map[string]bool{"management-aset.aset.view": true}, expires)
	c.put(7, "policy:m1|t1", &dataPolicy{anyAll: true, all: true, organizationIDs: map[string]bool{}}, expires)
	c.put(7, "organizations:t1", []object{
		{"id": "le1", "classification": "legal_entity"},
		{"id": "le2", "classification": "legal_entity"},
		{"id": "ou1", "classification": "operating_unit"},
	}, expires)
	s := &server{contextCache: c}
	// Tanpa koneksi database: setiap bagian konteks wajib datang dari cache.
	resolve := func(session object) *request {
		q := &request{server: s, r: httptest.NewRequest("GET", "/api/modules/management-aset/v1/aset", nil), session: session, user: object{"id": "u1"}, cacheVersion: 7}
		if e := q.resolveAuthority(""); e != nil {
			t.Fatal(e)
		}
		return q
	}
	first := resolve(object{"workspace.legal_entity_id": "le2"})
	second := resolve(object{})
	if first.authority.company != "le2" || second.authority.company != "le1" {
		t.Fatalf("company first=%q second=%q", first.authority.company, second.authority.company)
	}
	if first.authority.unit != "ou1" || second.session["workspace.legal_entity_id"] != "le1" || second.session["workspace.membership_id"] != "m1" {
		t.Fatalf("selection first unit=%q second session=%v", first.authority.unit, second.session)
	}
	if again := resolve(object{"workspace.legal_entity_id": "le2"}); again.authority.company != "le2" {
		t.Fatalf("selection leaked between requests: %q", again.authority.company)
	}
	if len(c.entries) != 4 {
		t.Fatalf("request-specific entries were cached: %d", len(c.entries))
	}
}
