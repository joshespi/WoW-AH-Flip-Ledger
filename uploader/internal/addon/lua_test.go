package addon

import (
	"os"
	"strings"
	"testing"
)

func render(t *testing.T, feed string) string {
	t.Helper()
	lua, _, err := Render([]byte(feed))
	if err != nil {
		t.Fatalf("Render: %v", err)
	}
	return string(lua)
}

func TestRenderGolden(t *testing.T) {
	got := render(t, `{"schema":1,"generated_at":1700000000,"region":"us","prices_stale":false,
		"summary":{"realized":120000,"unrealized":-5000},
		"positions":[{"item_id":238371,"name":"Wyrmfish","held":20,"floor":400000,"recent":null}],
		"alerts":[]}`)

	want := header + `WFL_FEED = {
  ["alerts"]={},
  ["generated_at"]=1700000000,
  ["positions"]={
    {["floor"]=400000,["held"]=20,["item_id"]=238371,["name"]="Wyrmfish"},
  },
  ["prices_stale"]=false,
  ["region"]="us",
  ["schema"]=1,
  ["summary"]={["realized"]=120000,["unrealized"]=-5000},
}
`
	if got != want {
		t.Errorf("got:\n%s\nwant:\n%s", got, want)
	}
}

func TestRenderEscapesStrings(t *testing.T) {
	cases := map[string]string{
		`quote " and \ slash`:   `"quote \" and \\ slash"`,
		"line\nbreak\rreturn":   `"line\nbreak\rreturn"`,
		"nul\u0000then1":        `"nul\000then1"`,
		"bell\u0007":            `"bell\007"`,
		"del\u007f":             `"del\127"`,
		"]] and [[ and --":      `"]] and [[ and --"`,
		"Ünïcode ✓ 龍":           `"Ünïcode ✓ 龍"`,
		"|cffff0000red|r":       `"|cffff0000red|r"`,
		"end\" } WFL_FEED = {}": `"end\" } WFL_FEED = {}"`,
	}

	for in, want := range cases {
		feed := `{"schema":1,"name":` + jsonString(in) + `}`
		if got := render(t, feed); !strings.Contains(got, `["name"]=`+want) {
			t.Errorf("%q: want %s in\n%s", in, want, got)
		}
	}
}

func TestRenderCannotBeInjectedThroughKeysOrValues(t *testing.T) {
	got := render(t, `{"schema":1,"a\"]=nil,[\"b":"x\"}; os.execute('x') --"}`)

	// The hostile text only ever appears inside quoted strings.
	if strings.Contains(got, "\n  [\"a\"]=nil") || !strings.Contains(got, `["a\"]=nil,[\"b"]="x\"}; os.execute('x') --"`) {
		t.Errorf("not escaped:\n%s", got)
	}
}

func TestRenderRejectsWhatItDoesNotKnow(t *testing.T) {
	cases := map[string]string{
		"not json":            `nope`,
		"trailing data":       `{"schema":1} {"x":1}`,
		"not an object":       `[1]`,
		"no schema":           `{"a":1}`,
		"newer schema":        `{"schema":2}`,
		"exponent number":     `{"schema":1,"n":1e5}`,
		"huge integer":        `{"schema":1,"n":12345678901234567890}`,
		"null inside a list":  `{"schema":1,"l":[1,null]}`,
		"too deep":            `{"schema":1,"a":{"b":{"c":{"d":{"e":{"f":{"g":1}}}}}}}`,
		"schema as a string":  `{"schema":"1"}`,
		"schema with decimal": `{"schema":1.0}`,
	}

	for name, feed := range cases {
		if _, _, err := Render([]byte(feed)); err == nil {
			t.Errorf("%s: expected an error", name)
		}
	}
}

func TestRenderDropsNullKeysAndKeepsDecimals(t *testing.T) {
	got := render(t, `{"schema":1,"gone":null,"pct":0.25,"neg":-3}`)

	if strings.Contains(got, "gone") {
		t.Errorf("null key should be dropped:\n%s", got)
	}
	if !strings.Contains(got, `["pct"]=0.25`) || !strings.Contains(got, `["neg"]=-3`) {
		t.Errorf("numbers wrong:\n%s", got)
	}
}

func TestSumIgnoresGeneratedAtOnly(t *testing.T) {
	sum := func(feed string) string {
		_, s, err := Render([]byte(feed))
		if err != nil {
			t.Fatal(err)
		}
		return s
	}

	a := sum(`{"schema":1,"generated_at":100,"floor":5}`)
	b := sum(`{"generated_at":999,"floor":5,"schema":1}`)
	c := sum(`{"schema":1,"generated_at":100,"floor":6}`)

	if a != b {
		t.Error("same content with a new generated_at should have the same sum")
	}
	if a == c {
		t.Error("changed content should change the sum")
	}
}

func TestRenderIsDeterministic(t *testing.T) {
	feed := `{"schema":1,"z":1,"a":{"y":2,"b":3,"m":4},"l":[{"q":1,"c":2}]}`

	first := render(t, feed)
	for range 20 {
		if render(t, feed) != first {
			t.Fatal("output order changed between runs")
		}
	}
}

// jsonString quotes s as a JSON string literal.
func jsonString(s string) string {
	var b strings.Builder
	b.WriteByte('"')
	for _, r := range s {
		switch {
		case r == '"' || r == '\\':
			b.WriteByte('\\')
			b.WriteRune(r)
		case r == '\n':
			b.WriteString(`\n`)
		case r == '\r':
			b.WriteString(`\r`)
		case r < 0x20 || r == 0x7f:
			b.WriteString(`\u00`)
			b.WriteString(string("0123456789abcdef"[r>>4]))
			b.WriteString(string("0123456789abcdef"[r&15]))
		default:
			b.WriteRune(r)
		}
	}
	b.WriteByte('"')
	return b.String()
}

// TestWriteSampleForLuaHarness renders a realistic feed to $WFL_SAMPLE_OUT so addon/tests/run.sh can
// load the exact bytes the uploader produces into the addon under Lua 5.1. Skipped otherwise.
func TestWriteSampleForLuaHarness(t *testing.T) {
	out := os.Getenv("WFL_SAMPLE_OUT")
	if out == "" {
		t.Skip("only used by addon/tests/run.sh")
	}

	feed := `{"schema":1,"generated_at":1700000000,"region":"us","prices_at":1699999000,"prices_stale":false,
	"summary":{"realized":1250000,"unrealized":-40000,"stock_at_floor":8000000},
	"truncated":false,
	"positions":[
	  {"item_id":238371,"name":"Wyrmfish","held":20,"avg_cost":380000,"break_even":400000,"target_sell":440000,"floor":520000,"recent":510000,"typical":700000,"unrealized":2300000,"status":"profit","market":"below","stats_ready":true},
	  {"item_id":238365,"name":"Swarmer |cffff0000\"Quoted\"|r","held":5,"avg_cost":3000,"break_even":3158,"target_sell":3474,"floor":2800,"recent":null,"typical":null,"unrealized":-1500,"status":"underwater","market":null,"stats_ready":false},
	  {"item_id":242639,"name":"Item #242639","held":100,"avg_cost":5000,"break_even":5264,"target_sell":5790,"floor":null,"recent":null,"typical":null,"unrealized":null,"status":null,"market":null,"stats_ready":false}
	],
	"watchlist":[
	  {"item_id":238371,"name":"Wyrmfish","buy":450000,"sell":900000,"floor":520000,"typical":700000,"at_buy":false,"at_sell":false,"knife":false},
	  {"item_id":262792,"name":"Bloomline","buy":35000,"sell":null,"floor":31000,"typical":30000,"at_buy":true,"at_sell":false,"knife":true}
	],
	"alerts":[
	  {"kind":"buy_target","item_id":262792,"name":"Bloomline","floor":31000,"target":35000},
	  {"kind":"profit","item_id":238371,"name":"Wyrmfish","floor":520000,"target":400000}
	]}`

	// WFL_SAMPLE_IN swaps in a feed captured from the real API.
	if in := os.Getenv("WFL_SAMPLE_IN"); in != "" {
		b, err := os.ReadFile(in)
		if err != nil {
			t.Fatal(err)
		}
		feed = string(b)
	}

	lua, _, err := Render([]byte(feed))
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(out, lua, 0o644); err != nil {
		t.Fatal(err)
	}
}
