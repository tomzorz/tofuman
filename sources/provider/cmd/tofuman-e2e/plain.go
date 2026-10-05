// SPDX-License-Identifier: MIT

package main

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"strings"
	"sync"
	"time"
)

// plain is the UI of REQ-E2E-18: one timestamped line per event, and answers read from the input,
// for a run without a terminal.
type plain struct {
	ctx context.Context
	out io.Writer
	in  *bufio.Scanner
	mu  sync.Mutex
}

func newPlain(ctx context.Context, in io.Reader, out io.Writer) *plain {
	return &plain{ctx: ctx, out: out, in: bufio.NewScanner(in)}
}

func (u *plain) printf(format string, args ...any) {
	u.mu.Lock()
	defer u.mu.Unlock()
	fmt.Fprintf(u.out, format, args...)
}

func (u *plain) event(mark, id, text string) {
	u.printf("%s  %-4s %-4s %s\n", clock(time.Now()), mark, id, text)
}

func (u *plain) Header(lines []string) {
	for _, line := range lines {
		u.printf("%s\n", line)
	}
}

func (u *plain) Steps([]Step) {}

func (u *plain) Update(s Step) {
	switch s.State {
	case stateRunning:
		u.event("..", s.ID, s.Title)
	case stateWaiting:
		u.event("??", s.ID, s.Title+": waiting for "+s.Detail)
	case statePassed:
		u.event("ok", s.ID, fmt.Sprintf("%s: %s (%s)", s.Title, s.Detail, took(s.Took)))
	case stateFailed:
		u.event("FAIL", s.ID, fmt.Sprintf("%s: %s (%s)", s.Title, s.Detail, took(s.Took)))
	}
}

func (u *plain) Progress(id, what string) {
	u.event("..", id, what)
}

func (u *plain) lines(lines []string) {
	for _, line := range lines {
		u.printf("      %s\n", line)
	}
}

// answer reads a yes or a no. The end of the input stops the run.
func (u *plain) answer(prompt string) (bool, error) {
	u.printf("      %s [y/n] ", prompt)
	if !u.in.Scan() {
		u.printf("\n")
		return false, errStopped
	}
	switch strings.ToLower(strings.TrimSpace(u.in.Text())) {
	case "y", "yes":
		return true, nil
	default:
		return false, nil
	}
}

func (u *plain) Confirm(_ Step, lines []string) (bool, error) {
	u.lines(lines)
	return u.answer("Does the webgui show that?")
}

func (u *plain) Await(_ Step, lines []string, done <-chan struct{}) error {
	u.lines(lines)
	select {
	case <-done:
		return nil
	case <-u.ctx.Done():
		return errStopped
	}
}

func (u *plain) Retry(_ Step, tail []string) (bool, error) {
	u.lines(tail)
	return u.answer("Run the step again?")
}

func (u *plain) Ask(question string) (bool, error) {
	return u.answer(question)
}

func (u *plain) Note(line string) {
	u.printf("%s\n", line)
}

func (u *plain) Finish(_ bool, lines []string) {
	u.printf("\n")
	for _, line := range lines {
		u.printf("%s\n", line)
	}
}
