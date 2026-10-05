// SPDX-License-Identifier: MIT

package main

import (
	"errors"
	"fmt"
	"time"
)

// State is where a step stands.
type State int

const (
	statePending State = iota
	stateRunning
	stateWaiting // for the person in the webgui
	statePassed
	stateFailed
)

// Step is one step of the procedure, as a UI shows it (REQ-E2E-15). The procedure hands out
// copies, so a UI never reads a step while the procedure writes it.
type Step struct {
	ID     string
	Title  string
	State  State
	Began  time.Time
	Took   time.Duration
	Detail string // what the step found, why it failed, or what it waits for
}

// errStopped ends a run that the person stopped.
var errStopped = errors.New("stopped by the person")

// UI is what the procedure needs from the person: the interactive view, the plain lines of
// REQ-E2E-18, or the scripted person of the acceptance test.
type UI interface {
	// Header shows the facts of the run, one per line.
	Header(lines []string)
	// Steps shows every step before the first one runs.
	Steps(steps []Step)
	// Update shows the new state of one step.
	Update(step Step)
	// Progress tells what a running step does right now, such as "tofu apply".
	Progress(id, what string)
	// Confirm asks whether the webgui shows what the lines describe (REQ-E2E-13).
	Confirm(step Step, lines []string) (bool, error)
	// Await shows the lines while the procedure watches the server (REQ-E2E-12). It returns nil
	// once done closes, and errStopped if the person stops the run first.
	Await(step Step, lines []string, done <-chan struct{}) error
	// Retry asks whether to run a failed step again; tail is the end of the output of tofu
	// (REQ-E2E-16).
	Retry(step Step, tail []string) (bool, error)
	// Ask asks a question outside the steps, such as whether to delete leftovers.
	Ask(question string) (bool, error)
	// Note shows a line outside the steps.
	Note(line string)
	// Finish shows the result of the run.
	Finish(passed bool, lines []string)
}

// clock is how both views show the moment a step began.
func clock(t time.Time) string {
	if t.IsZero() {
		return ""
	}
	return t.Local().Format("15:04:05")
}

// took is how both views show the duration of a step.
func took(d time.Duration) string {
	switch {
	case d < time.Second:
		return fmt.Sprintf("%d ms", d.Milliseconds())
	case d < time.Minute:
		return fmt.Sprintf("%.1f s", d.Seconds())
	default:
		return fmt.Sprintf("%d:%02d", int(d.Minutes()), int(d.Seconds())%60)
	}
}
