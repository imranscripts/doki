package model

import "testing"

func TestInterpolateScriptSupportsHyphenatedPaths(t *testing.T) {
	values := map[string]interface{}{
		"steps": map[string]interface{}{
			"fetch-char": map[string]interface{}{
				"output": "Luke Skywalker",
				"extract": map[string]interface{}{
					"homeworld-url": "https://swapi.py4e.com/api/planets/1/",
				},
			},
		},
	}

	got := InterpolateScript(
		"Character={{steps.fetch-char.output}} Homeworld={{steps.fetch-char.extract.homeworld-url}}",
		values,
	)

	want := "Character=Luke Skywalker Homeworld=https://swapi.py4e.com/api/planets/1/"
	if got != want {
		t.Fatalf("InterpolateScript() = %q, want %q", got, want)
	}
}

func TestInterpolateScriptSupportsHyphenatedIfPaths(t *testing.T) {
	values := map[string]interface{}{
		"steps": map[string]interface{}{
			"fetch-char": map[string]interface{}{
				"status": "completed",
			},
		},
	}

	got := InterpolateScript("{{#if steps.fetch-char.status}}done{{/if}}", values)
	if got != "done" {
		t.Fatalf("InterpolateScript() = %q, want %q", got, "done")
	}
}
