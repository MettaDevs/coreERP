package main

import (
	"math/big"
	"reflect"
	"testing"

	"github.com/jackc/pgx/v5/pgtype"
)

func TestPostgresNumericZeroKeepsDeclaredScale(t *testing.T) {
	zero := pgtype.Numeric{Int: big.NewInt(0), Exp: 0, Valid: true}
	for _, test := range []struct {
		scale int32
		want  string
	}{{2, "0.00"}, {6, "0.000000"}} {
		modifier := (int32(24) << 16) | test.scale
		got := dbValue(zero, 1700, modifier+4)
		if got != test.want {
			t.Fatalf("numeric(24,%d)=%v, want %s", test.scale, got, test.want)
		}
	}
}

func TestReceiptAllocationPreservesRoundedExtendedAmounts(t *testing.T) {
	for _, test := range []struct {
		name, price, total string
		want               []string
	}{
		{"acquisition", "333333.333", "1000000.00", []string{"333333.34", "333333.33", "333333.33"}},
		{"tax", "33333.333", "100000.00", []string{"33333.34", "33333.33", "33333.33"}},
		{"halfUp", "0.005", "0.02", []string{"0.01", "0.01", "0.00"}},
	} {
		t.Run(test.name, func(t *testing.T) {
			total := extended(test.price, 3, 2)
			if total != test.total {
				t.Fatalf("extended = %s, want %s", total, test.total)
			}
			parts := allocate(total, 3, 2)
			if !reflect.DeepEqual(parts, test.want) {
				t.Fatalf("allocated = %v, want %v", parts, test.want)
			}
			sum := money("0")
			for _, v := range parts {
				sum = sum.Add(money(v))
			}
			if sum.StringFixed(2) != total {
				t.Fatalf("sum = %s, total = %s", sum, total)
			}
		})
	}
}

func TestLocationScopeRequiresCompanyAndResponsibleUnit(t *testing.T) {
	a := authority{company: "company-a", grants: []grant{{company: "company-a", units: map[string]bool{"department-a": true}}}}
	for _, probe := range []struct {
		company, unit string
		want          bool
	}{{"company-a", "department-a", true}, {"company-a", "department-b", false}, {"company-b", "department-a", false}, {"", "department-a", false}} {
		if got := a.allows(probe.company, probe.unit); got != probe.want {
			t.Fatalf("scope(%s,%s) = %v", probe.company, probe.unit, got)
		}
	}
	a.all = true
	if a.allows("company-b", "department-a") {
		t.Fatal("all policy bypassed selected company")
	}
}

func TestDepreciationStartUsesConvention(t *testing.T) {
	for _, test := range []struct{ date, convention, want string }{{"2026-11-03", "full_month", "2026-11-01"}, {"2026-11-23", "mid_month_1st", "2026-12-01"}, {"2026-11-03", "none", "2026-11-03"}} {
		got, e := depreciationStart(test.date, test.convention)
		if e != nil || got != test.want {
			t.Fatalf("start(%s,%s)=%s,%v", test.date, test.convention, got, e)
		}
	}
}
