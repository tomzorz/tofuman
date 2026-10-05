// SPDX-License-Identifier: MIT

package main

import (
	"bytes"
	"context"
	"regexp"
	"strings"
	"testing"
	"time"

	tea "charm.land/bubbletea/v2"
)

// REQ-E2E-15 and REQ-E2E-18: a timestamp and a mark on each line, the answers from the input,
// and the end of the input as a stop.
func TestPlainLinesAndAnswers(t *testing.T) {
	var out bytes.Buffer
	u := newPlain(context.Background(), strings.NewReader("y\nno\n"), &out)
	began := time.Now()
	u.Update(Step{ID: "1-2", Title: "Create", State: stateRunning, Began: began})
	u.Update(Step{ID: "1-2", Title: "Create", State: statePassed, Began: began, Took: 1500 * time.Millisecond, Detail: "created"})
	if yes, err := u.Confirm(Step{ID: "3"}, []string{"check it"}); !yes || err != nil {
		t.Fatalf("y became %v, %v", yes, err)
	}
	if yes, err := u.Ask("Delete them?"); yes || err != nil {
		t.Fatalf("no became %v, %v", yes, err)
	}
	if _, err := u.Retry(Step{ID: "9"}, []string{"the tail"}); err != errStopped {
		t.Fatalf("the end of the input became %v", err)
	}
	lines := strings.Split(out.String(), "\n")
	if !regexp.MustCompile(`^\d\d:\d\d:\d\d  ok   1-2  Create: created \(1\.5 s\)$`).MatchString(lines[1]) {
		t.Fatalf("the line of a passed step is %q", lines[1])
	}
	if !strings.Contains(out.String(), "      check it\n") || !strings.Contains(out.String(), "      the tail\n") {
		t.Fatalf("the lines of a question are missing:\n%s", out.String())
	}
}

// The view answers a question with the key that the person pressed, and a q stops the run.
func TestTheViewAnswersWithTheKeyOfThePerson(t *testing.T) {
	stopped := false
	var m tea.Model = newModel(func() { stopped = true })
	m, _ = m.Update(stepsMsg{{ID: "3", Title: "Look in the webgui"}, {ID: "4", Title: "Edit in the webgui"}})
	m, _ = m.Update(stepMsg{ID: "3", Title: "Look in the webgui", State: stateWaiting, Began: time.Now(), Detail: "a look in the webgui"})
	answers := make(chan reply, 1)
	m, _ = m.Update(promptMsg{kind: confirmPrompt, step: Step{ID: "3", Title: "Look in the webgui"}, lines: []string{"• it runs"}, reply: answers})
	view := m.View().Content
	for _, want := range []string{"Look in the webgui", "waiting for a look in the webgui", "• it runs", "it does not"} {
		if !strings.Contains(view, want) {
			t.Errorf("the view lacks %q:\n%s", want, view)
		}
	}
	m, _ = m.Update(tea.KeyPressMsg{Code: 'y', Text: "y"})
	if r := <-answers; !r.yes || r.err != nil {
		t.Fatalf("y became %+v", r)
	}
	m, _ = m.Update(promptMsg{kind: awaitPrompt, step: Step{ID: "4", Title: "Edit in the webgui"}, reply: answers, since: time.Now()})
	m, _ = m.Update(tea.KeyPressMsg{Code: 'q', Text: "q"})
	if r := <-answers; r.err != errStopped || !stopped {
		t.Fatalf("q became %+v, and the run stopped: %v", r, stopped)
	}
	if !strings.Contains(m.View().Content, "Stopping") {
		t.Fatalf("the view does not say that the run stops:\n%s", m.View().Content)
	}
}
