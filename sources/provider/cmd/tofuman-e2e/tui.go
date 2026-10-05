// SPDX-License-Identifier: MIT

package main

import (
	"context"
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"charm.land/bubbles/v2/spinner"
	tea "charm.land/bubbletea/v2"
	"charm.land/lipgloss/v2"
)

// tui is the interactive view of REQ-E2E-15: the steps with their state, start time, and
// duration, and below them what the run needs from the person. It draws in place rather than
// on the alternate screen, so the last frame stays in the terminal after the run.
type tui struct {
	program *tea.Program
	quit    chan struct{} // closed once the view has ended
}

type (
	headerMsg      []string
	stepsMsg       []Step
	stepMsg        Step
	noteMsg        string
	closePromptMsg struct{}
	progressMsg    struct{ id, what string }
	finishMsg      struct {
		passed bool
		lines  []string
	}
)

type promptKind int

const (
	confirmPrompt promptKind = iota
	awaitPrompt
	retryPrompt
	askPrompt
)

// promptMsg is a question to the person; the answer goes back on reply, which holds one.
type promptMsg struct {
	kind  promptKind
	step  Step
	lines []string
	reply chan<- reply
	since time.Time
}

type reply struct {
	yes bool
	err error
}

var (
	brand  = lipgloss.NewStyle().Foreground(lipgloss.Color("208")).Bold(true)
	dim    = lipgloss.NewStyle().Foreground(lipgloss.Color("8"))
	bold   = lipgloss.NewStyle().Bold(true)
	green  = lipgloss.NewStyle().Foreground(lipgloss.Color("2"))
	red    = lipgloss.NewStyle().Foreground(lipgloss.Color("1"))
	yellow = lipgloss.NewStyle().Foreground(lipgloss.Color("3"))
	cyan   = lipgloss.NewStyle().Foreground(lipgloss.Color("6"))
	box    = lipgloss.NewStyle().Border(lipgloss.ThickBorder(), false, false, false, true).BorderForeground(lipgloss.Color("3")).PaddingLeft(1)
)

func newTUI(stop context.CancelFunc) *tui {
	return &tui{program: tea.NewProgram(newModel(stop)), quit: make(chan struct{})}
}

// run shows the view until the procedure finishes, or until the person leaves at once.
func (t *tui) run() error {
	defer close(t.quit)
	_, err := t.program.Run()
	return err
}

func (t *tui) Header(lines []string)              { t.program.Send(headerMsg(lines)) }
func (t *tui) Steps(steps []Step)                 { t.program.Send(stepsMsg(steps)) }
func (t *tui) Update(s Step)                      { t.program.Send(stepMsg(s)) }
func (t *tui) Progress(id, what string)           { t.program.Send(progressMsg{id, what}) }
func (t *tui) Note(line string)                   { t.program.Send(noteMsg(line)) }
func (t *tui) Finish(passed bool, lines []string) { t.program.Send(finishMsg{passed, lines}) }

func (t *tui) Confirm(s Step, lines []string) (bool, error) { return t.ask(confirmPrompt, s, lines) }
func (t *tui) Retry(s Step, tail []string) (bool, error)    { return t.ask(retryPrompt, s, tail) }
func (t *tui) Ask(question string) (bool, error)            { return t.ask(askPrompt, Step{}, []string{question}) }

func (t *tui) ask(kind promptKind, s Step, lines []string) (bool, error) {
	answers := make(chan reply, 1)
	t.program.Send(promptMsg{kind: kind, step: s, lines: lines, reply: answers, since: time.Now()})
	select {
	case r := <-answers:
		return r.yes, r.err
	case <-t.quit:
		return false, errStopped
	}
}

func (t *tui) Await(s Step, lines []string, done <-chan struct{}) error {
	answers := make(chan reply, 1)
	t.program.Send(promptMsg{kind: awaitPrompt, step: s, lines: lines, reply: answers, since: time.Now()})
	select {
	case <-done:
		t.program.Send(closePromptMsg{})
		return nil
	case r := <-answers:
		return r.err
	case <-t.quit:
		return errStopped
	}
}

type model struct {
	header   []string
	steps    []Step
	progress map[string]string
	notes    []string
	prompt   *promptMsg
	spinner  spinner.Model
	width    int
	stopping bool
	result   *finishMsg
	stop     context.CancelFunc
}

func newModel(stop context.CancelFunc) model {
	return model{
		progress: map[string]string{},
		spinner:  spinner.New(spinner.WithSpinner(spinner.MiniDot), spinner.WithStyle(cyan)),
		width:    100,
		stop:     stop,
	}
}

func (m model) Init() tea.Cmd {
	return m.spinner.Tick
}

func (m model) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		m.width = msg.Width
	case spinner.TickMsg:
		var cmd tea.Cmd
		m.spinner, cmd = m.spinner.Update(msg)
		return m, cmd
	case headerMsg:
		m.header = msg
	case stepsMsg:
		m.steps = append([]Step(nil), msg...)
	case stepMsg:
		for i := range m.steps {
			if m.steps[i].ID == msg.ID {
				m.steps[i] = Step(msg)
			}
		}
		if msg.State != stateRunning {
			delete(m.progress, msg.ID)
		}
	case progressMsg:
		m.progress[msg.id] = msg.what
	case noteMsg:
		m.notes = append(m.notes, string(msg))
	case promptMsg:
		m.prompt = &msg
	case closePromptMsg:
		m.prompt = nil
	case finishMsg:
		m.result, m.prompt = &msg, nil
		return m, tea.Quit
	case tea.KeyPressMsg:
		return m.key(msg.String())
	}
	return m, nil
}

func (m model) key(key string) (tea.Model, tea.Cmd) {
	if key == "ctrl+c" && m.stopping {
		return m, tea.Quit // the second ctrl+c does not wait for tofu
	}
	if m.prompt != nil {
		switch m.prompt.kind {
		case confirmPrompt, askPrompt:
			switch key {
			case "y":
				return m.answer(true)
			case "n":
				return m.answer(false)
			}
		case retryPrompt:
			switch key {
			case "r", "y":
				return m.answer(true)
			case "s", "n":
				return m.answer(false)
			}
		}
	}
	if key == "q" || key == "ctrl+c" {
		m.stopping = true
		if m.prompt != nil {
			m.prompt.reply <- reply{err: errStopped}
			m.prompt = nil
		}
		m.stop() // a running tofu gets an interrupt and ends its command
	}
	return m, nil
}

func (m model) answer(yes bool) (tea.Model, tea.Cmd) {
	m.prompt.reply <- reply{yes: yes}
	m.prompt = nil
	return m, nil
}

func (m model) View() tea.View {
	var b strings.Builder
	b.WriteString(brand.Render("tofuman"))
	for i, line := range m.header {
		if i == 0 {
			b.WriteString("  " + line)
		} else {
			b.WriteString("\n" + dim.Render(line))
		}
	}
	b.WriteString("\n\n")
	for _, s := range m.steps {
		b.WriteString(m.row(s) + "\n")
	}
	if m.prompt != nil {
		b.WriteString("\n" + m.promptView() + "\n")
	}
	for _, note := range m.notes {
		b.WriteString("\n" + note)
	}
	switch {
	case m.result != nil:
		mark := green.Render("Every step passed.")
		if !m.result.passed {
			mark = red.Render("The run stopped.") + " The throwaway containers stay as they are; the diagnostics file of the tofuman tab helps find the cause."
		}
		b.WriteString("\n" + mark + "\n" + dim.Render(lastLine(m.result.lines)+", with summary.txt and a log of each tofu command"))
	case m.stopping:
		b.WriteString("\n" + yellow.Render("Stopping: a running tofu command ends first.") + dim.Render(" ctrl+c again leaves at once."))
	case m.prompt == nil:
		b.WriteString("\n" + keys("q", "stop the run"))
	}
	return tea.NewView(b.String() + "\n")
}

// row is one step: its state, number, title, what it found or does, when it began, and how long
// it took or runs.
func (m model) row(s Step) string {
	icon, detail, length := "", s.Detail, ""
	title := s.Title
	switch s.State {
	case statePending:
		icon, title = dim.Render("○"), dim.Render(pad(s.Title, 21))
	case stateRunning:
		icon, length = m.spinner.View(), elapsed(time.Since(s.Began))
		if what := m.progress[s.ID]; what != "" {
			detail = what
		}
	case stateWaiting:
		icon, detail, length = yellow.Render("◐"), "waiting for "+s.Detail, elapsed(time.Since(s.Began))
	case statePassed:
		icon, length = green.Render("✓"), took(s.Took)
	case stateFailed:
		icon, length = red.Render("✗"), took(s.Took)
	}
	if s.State != statePending {
		title = pad(s.Title, 21)
	}
	room := max(m.width-2-2-5-22-20, 12)
	right := fmt.Sprintf("%8s %8s", clock(s.Began), length)
	text := pad(fit(detail, room), room)
	if s.State == stateFailed {
		text = red.Render(text)
	}
	return fmt.Sprintf("  %s %-4s %s %s  %s", icon, s.ID, title, text, dim.Render(right))
}

func (m model) promptView() string {
	p := m.prompt
	var b strings.Builder
	switch p.kind {
	case confirmPrompt:
		b.WriteString(bold.Render("Step "+p.step.ID+" · "+p.step.Title) + "\n")
		b.WriteString(strings.Join(p.lines, "\n") + "\n\n")
		b.WriteString(keys("y", "it does", "n", "it does not", "q", "stop the run"))
	case awaitPrompt:
		b.WriteString(bold.Render("Step "+p.step.ID+" · "+p.step.Title) + "\n")
		b.WriteString(strings.Join(p.lines, "\n") + "\n\n")
		b.WriteString(m.spinner.View() + dim.Render(" watching the server · "+elapsed(time.Since(p.since))) + "\n")
		b.WriteString(keys("q", "stop the run"))
	case retryPrompt:
		b.WriteString(red.Render("Step "+p.step.ID+" failed: ") + p.step.Detail + "\n")
		for _, line := range p.lines {
			b.WriteString(dim.Render(line) + "\n")
		}
		b.WriteString("\n" + keys("r", "run it again", "s", "stop the run; the containers stay"))
	case askPrompt:
		b.WriteString(strings.Join(p.lines, "\n") + "\n\n")
		b.WriteString(keys("y", "yes", "n", "no"))
	}
	return box.Render(b.String())
}

// keys shows key bindings as pairs of a key and what it does.
func keys(pairs ...string) string {
	var parts []string
	for i := 0; i+1 < len(pairs); i += 2 {
		parts = append(parts, bold.Render(pairs[i])+" "+dim.Render(pairs[i+1]))
	}
	return strings.Join(parts, dim.Render("  ·  "))
}

func elapsed(d time.Duration) string {
	seconds := int(d.Seconds())
	return fmt.Sprintf("%d:%02d", seconds/60, seconds%60)
}

func fit(s string, width int) string {
	if utf8.RuneCountInString(s) <= width {
		return s
	}
	runes := []rune(s)
	return string(runes[:width-1]) + "…"
}

func pad(s string, width int) string {
	if n := utf8.RuneCountInString(s); n < width {
		return s + strings.Repeat(" ", width-n)
	}
	return s
}

func lastLine(lines []string) string {
	if len(lines) == 0 {
		return ""
	}
	return lines[len(lines)-1]
}
