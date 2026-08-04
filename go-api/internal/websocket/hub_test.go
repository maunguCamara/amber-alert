package websocket_test

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	gorillaws "github.com/gorilla/websocket"
	"localhost/amberalert/internal/models"
	"localhost/amberalert/internal/websocket"
	"go.uber.org/zap"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func newHub(t *testing.T) *websocket.Hub {
	t.Helper()
	log, _ := zap.NewDevelopment()
	hub := websocket.NewHub(log)
	go hub.Run()
	return hub
}

// dialHub spins up a test HTTP server backed by the hub and dials a WebSocket client.
// county may be empty for a global subscriber.
func dialHub(t *testing.T, hub *websocket.Hub, county string) *gorillaws.Conn {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		err := hub.Upgrade(w, r, county)
		require.NoError(t, err)
	}))
	t.Cleanup(srv.Close)

	url := "ws" + strings.TrimPrefix(srv.URL, "http")
	conn, _, err := gorillaws.DefaultDialer.Dial(url, nil)
	require.NoError(t, err)
	t.Cleanup(func() { conn.Close() })
	return conn
}

func readEvent(t *testing.T, conn *gorillaws.Conn, timeout time.Duration) (models.WSEvent, bool) {
	t.Helper()
	conn.SetReadDeadline(time.Now().Add(timeout))
	_, msg, err := conn.ReadMessage()
	if err != nil {
		return models.WSEvent{}, false
	}
	var event models.WSEvent
	require.NoError(t, json.Unmarshal(msg, &event))
	return event, true
}

// ── ConnectedCount ────────────────────────────────────────────────────────────

func TestHub_ConnectedCount_StartsZero(t *testing.T) {
	hub := newHub(t)
	assert.Equal(t, 0, hub.ConnectedCount())
}

func TestHub_ConnectedCount_IncrementsOnConnect(t *testing.T) {
	hub := newHub(t)
	dialHub(t, hub, "")
	time.Sleep(50 * time.Millisecond) // let the server goroutine register
	assert.Equal(t, 1, hub.ConnectedCount())
}

func TestHub_ConnectedCount_TracksMultipleClients(t *testing.T) {
	hub := newHub(t)
	for i := 0; i < 3; i++ {
		dialHub(t, hub, "")
	}
	time.Sleep(80 * time.Millisecond)
	assert.Equal(t, 3, hub.ConnectedCount())
}

// ── Broadcast — global (no county filter) ────────────────────────────────────

func TestHub_Broadcast_GlobalSubscriberReceivesEvent(t *testing.T) {
	hub := newHub(t)
	conn := dialHub(t, hub, "") // no county = all events
	time.Sleep(50 * time.Millisecond)

	event := models.WSEvent{
		Type:    "case.new",
		Payload: map[string]any{"id": "abc", "county": "Nairobi"},
	}
	hub.Broadcast(event, "Nairobi")

	got, ok := readEvent(t, conn, 500*time.Millisecond)
	require.True(t, ok, "global subscriber should receive the event")
	assert.Equal(t, "case.new", got.Type)
}

// ── Broadcast — county filter ─────────────────────────────────────────────────

func TestHub_Broadcast_CountySubscriberReceivesMatchingEvent(t *testing.T) {
	hub := newHub(t)
	nairobi := dialHub(t, hub, "Nairobi")
	time.Sleep(50 * time.Millisecond)

	hub.Broadcast(models.WSEvent{Type: "case.new"}, "Nairobi")

	_, ok := readEvent(t, nairobi, 500*time.Millisecond)
	assert.True(t, ok, "Nairobi subscriber should receive a Nairobi event")
}

func TestHub_Broadcast_CountySubscriberSkipsOtherCountyEvent(t *testing.T) {
	hub := newHub(t)
	mombasa := dialHub(t, hub, "Mombasa")
	time.Sleep(50 * time.Millisecond)

	hub.Broadcast(models.WSEvent{Type: "case.new"}, "Nairobi")

	_, ok := readEvent(t, mombasa, 200*time.Millisecond)
	assert.False(t, ok, "Mombasa subscriber must NOT receive a Nairobi-only event")
}

func TestHub_Broadcast_EmptyCounty_BroadcastsToAll(t *testing.T) {
	hub := newHub(t)
	nairobiConn := dialHub(t, hub, "Nairobi")
	mombasaConn := dialHub(t, hub, "Mombasa")
	globalConn  := dialHub(t, hub, "")
	time.Sleep(80 * time.Millisecond)

	// Broadcast with empty county = send to everyone
	hub.Broadcast(models.WSEvent{Type: "case.resolved"}, "")

	for _, conn := range []*gorillaws.Conn{nairobiConn, mombasaConn, globalConn} {
		_, ok := readEvent(t, conn, 400*time.Millisecond)
		assert.True(t, ok, "all clients should receive empty-county broadcast")
	}
}

// ── Disconnect ────────────────────────────────────────────────────────────────

func TestHub_ClientDisconnect_DecreasesCount(t *testing.T) {
	hub := newHub(t)
	conn := dialHub(t, hub, "")
	time.Sleep(50 * time.Millisecond)
	require.Equal(t, 1, hub.ConnectedCount())

	conn.Close()
	time.Sleep(100 * time.Millisecond) // allow server to detect close
	assert.Equal(t, 0, hub.ConnectedCount())
}

// ── Event type fidelity ───────────────────────────────────────────────────────

func TestHub_Broadcast_EventTypeIsPreserved(t *testing.T) {
	hub := newHub(t)
	conn := dialHub(t, hub, "")
	time.Sleep(50 * time.Millisecond)

	for _, evType := range []string{"case.new", "case.updated", "case.resolved"} {
		hub.Broadcast(models.WSEvent{Type: evType}, "")
		got, ok := readEvent(t, conn, 300*time.Millisecond)
		require.True(t, ok)
		assert.Equal(t, evType, got.Type)
	}
}