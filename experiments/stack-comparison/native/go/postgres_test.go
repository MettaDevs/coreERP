package main

import (
	"context"
	"os"
	"testing"

	"github.com/jackc/pgx/v5"
)

func TestPostgresNumericScaleReadback(t *testing.T) {
	if os.Getenv("NATIVE_GO_READONLY_TEST") != "1" {
		t.Skip("set NATIVE_GO_READONLY_TEST=1 and standard PG connection variables")
	}
	ctx := context.Background()
	conn, e := pgx.Connect(ctx, "")
	if e != nil {
		t.Fatal(e)
	}
	defer conn.Close(ctx)
	r, e := rows(ctx, conn, "select 0::numeric(24,2) as amount,0::numeric(24,6) as unit,333333.340000::numeric(24,2) as allocation")
	if e != nil {
		t.Fatal(e)
	}
	for name, want := range map[string]string{"amount": "0.00", "unit": "0.000000", "allocation": "333333.34"} {
		if got := r[0][name]; got != want {
			t.Fatalf("%s=%v, want %s", name, got, want)
		}
	}
}
