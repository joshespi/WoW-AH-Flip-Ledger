package addon

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"maps"
	"regexp"
	"sort"
	"strings"
)

// SupportedSchema is the feed layout this uploader knows how to render.
const SupportedSchema = 1

const (
	maxDepth = 6

	// Lua numbers are doubles; stay well inside the range where integers are exact.
	maxDigits = 15

	header = "-- Written by the WoW Flip Ledger uploader. Do not edit; it is replaced on every sync.\n"
)

var numberRE = regexp.MustCompile(`^-?\d+(\.\d+)?$`)

// Render turns the server's feed JSON into the text of Data.lua.
//
// The server's JSON is never pasted into code: it is decoded, checked, and re-encoded by this
// strict serializer, which accepts only objects, arrays, strings, plain numbers and booleans
// (null drops the key), escapes every string, and emits keys as quoted strings. sum identifies the
// content apart from generated_at, so an unchanged feed does not rewrite the file.
func Render(feed []byte) (lua []byte, sum string, err error) {
	dec := json.NewDecoder(bytes.NewReader(feed))
	dec.UseNumber()

	var v any
	if err := dec.Decode(&v); err != nil {
		return nil, "", fmt.Errorf("the addon feed is not valid JSON: %w", err)
	}
	if _, err := dec.Token(); !errors.Is(err, io.EOF) {
		return nil, "", errors.New("the addon feed has trailing data")
	}

	root, ok := v.(map[string]any)
	if !ok {
		return nil, "", errors.New("the addon feed is not a JSON object")
	}
	if schema, _ := root["schema"].(json.Number); schema.String() != fmt.Sprint(SupportedSchema) {
		return nil, "", fmt.Errorf("the addon feed uses schema %q; this uploader supports %d (update the uploader)", schema.String(), SupportedSchema)
	}

	full, err := encode(root)
	if err != nil {
		return nil, "", err
	}
	rest := maps.Clone(root)
	delete(rest, "generated_at")
	stable, err := encode(rest)
	if err != nil {
		return nil, "", err
	}

	h := sha256.Sum256(stable)
	return []byte(header + "WFL_FEED = " + string(full) + "\n"), hex.EncodeToString(h[:]), nil
}

// encode writes the top-level object.
func encode(root map[string]any) ([]byte, error) {
	var b bytes.Buffer
	if err := writeObject(&b, root, 0); err != nil {
		return nil, err
	}
	return b.Bytes(), nil
}

func writeValue(b *bytes.Buffer, v any, depth int) error {
	if depth > maxDepth {
		return errors.New("the addon feed is nested too deeply")
	}

	switch x := v.(type) {
	case string:
		b.WriteString(quote(x))
	case bool:
		fmt.Fprint(b, x)
	case json.Number:
		return writeNumber(b, x)
	case map[string]any:
		return writeObject(b, x, depth)
	case []any:
		return writeArray(b, x, depth)
	default:
		return fmt.Errorf("the addon feed has an unsupported value (%T)", v)
	}
	return nil
}

func writeNumber(b *bytes.Buffer, n json.Number) error {
	s := n.String()
	if !numberRE.MatchString(s) {
		return fmt.Errorf("the addon feed has an unsupported number %q", s)
	}
	whole, _, _ := strings.Cut(strings.TrimPrefix(s, "-"), ".")
	if len(whole) > maxDigits {
		return fmt.Errorf("the addon feed has a number too large for Lua: %q", s)
	}
	b.WriteString(s)
	return nil
}

// writeObject sorts keys so equal content renders identically. The top-level object gets a line
// per key, and lists of tables a line per element, to keep Data.lua readable; the rest is inline.
func writeObject(b *bytes.Buffer, m map[string]any, depth int) error {
	keys := make([]string, 0, len(m))
	for k, v := range m {
		if v != nil {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)

	multi := depth == 0
	b.WriteByte('{')
	for i, k := range keys {
		if multi {
			b.WriteString("\n  ")
		} else if i > 0 {
			b.WriteByte(',')
		}
		b.WriteString("[" + quote(k) + "]=")
		if err := writeValue(b, m[k], depth+1); err != nil {
			return err
		}
		if multi {
			b.WriteByte(',')
		}
	}
	if multi {
		b.WriteByte('\n')
	}
	b.WriteByte('}')
	return nil
}

func writeArray(b *bytes.Buffer, a []any, depth int) error {
	tables := false
	for _, e := range a {
		switch e.(type) {
		case nil:
			return errors.New("the addon feed has a null inside a list")
		case map[string]any, []any:
			tables = true
		}
	}

	b.WriteByte('{')
	for i, e := range a {
		switch {
		case tables:
			b.WriteString("\n    ")
		case i > 0:
			b.WriteByte(',')
		}
		if err := writeValue(b, e, depth+1); err != nil {
			return err
		}
		if tables {
			b.WriteByte(',')
		}
	}
	if tables {
		b.WriteString("\n  ")
	}
	b.WriteByte('}')
	return nil
}

// quote writes s as a Lua double-quoted string. Control characters become three-digit decimal
// escapes (unambiguous before a digit); everything else is valid UTF-8 from the JSON decoder.
func quote(s string) string {
	var b strings.Builder
	b.WriteByte('"')
	for _, r := range s {
		switch {
		case r == '\\':
			b.WriteString(`\\`)
		case r == '"':
			b.WriteString(`\"`)
		case r == '\n':
			b.WriteString(`\n`)
		case r == '\r':
			b.WriteString(`\r`)
		case r < 0x20 || r == 0x7f:
			fmt.Fprintf(&b, `\%03d`, r)
		default:
			b.WriteRune(r)
		}
	}
	b.WriteByte('"')
	return b.String()
}
