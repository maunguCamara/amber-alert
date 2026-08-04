package websocket

import (
	"encoding/json"
	"net/http"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"localhost/amberalert/internal/models"
	"go.uber.org/zap"
)

const (
	writeWait      = 10 * time.Second
	pongWait       = 60 * time.Second
	pingPeriod     = (pongWait * 9) / 10
	maxMessageSize = 512
)

var upgrader = websocket.Upgrader{
	ReadBufferSize:  1024,
	WriteBufferSize: 4096,
	CheckOrigin: func(r *http.Request) bool {
		// Origin checking is enforced at the Gin middleware layer.
		return true
	},
}

// Client represents a single connected browser.
type Client struct {
	hub    *Hub
	conn   *websocket.Conn
	send   chan []byte
	county string // if set, client only receives events for this county
}

// Hub manages the full set of active WebSocket clients.
type Hub struct {
	mu      sync.RWMutex
	clients map[*Client]struct{}
	log     *zap.Logger
}

func NewHub(log *zap.Logger) *Hub {
	return &Hub{
		clients: make(map[*Client]struct{}),
		log:     log,
	}
}

// Run is the hub's main goroutine — not strictly needed without channels,
// but kept for future backpressure / metrics hooks.
func (h *Hub) Run() {
	// no-op event loop; client registration is mutex-guarded directly.
}

// Register adds a new client to the hub.
func (h *Hub) Register(c *Client) {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.clients[c] = struct{}{}
	h.log.Debug("ws client registered", zap.Int("total", len(h.clients)))
}

// Unregister removes a client and closes its send channel.
func (h *Hub) Unregister(c *Client) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if _, ok := h.clients[c]; ok {
		delete(h.clients, c)
		close(c.send)
	}
}

// Broadcast sends an event to all (or county-filtered) clients.
func (h *Hub) Broadcast(event models.WSEvent, county string) {
	payload, err := json.Marshal(event)
	if err != nil {
		h.log.Error("ws marshal failed", zap.Error(err))
		return
	}

	h.mu.RLock()
	defer h.mu.RUnlock()

	for c := range h.clients {
		if county != "" && c.county != "" && c.county != county {
			continue // geo-filter: skip clients subscribed to a different county
		}
		select {
		case c.send <- payload:
		default:
			// Slow client — drop message rather than block hub.
			h.log.Warn("ws client send buffer full, dropping message")
		}
	}
}

// ConnectedCount returns the number of live connections.
func (h *Hub) ConnectedCount() int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return len(h.clients)
}

// Upgrade upgrades an HTTP connection and starts the client pump goroutines.
func (h *Hub) Upgrade(w http.ResponseWriter, r *http.Request, county string) error {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		return err
	}

	client := &Client{
		hub:    h,
		conn:   conn,
		send:   make(chan []byte, 256),
		county: county,
	}
	h.Register(client)
	go client.writePump()
	go client.readPump()
	return nil
}

// ─── Client pumps ─────────────────────────────────────────────────────────────

func (c *Client) readPump() {
	defer func() {
		c.hub.Unregister(c)
		c.conn.Close()
	}()

	c.conn.SetReadLimit(maxMessageSize)
	c.conn.SetReadDeadline(time.Now().Add(pongWait))   //nolint:errcheck
	c.conn.SetPongHandler(func(string) error {
		c.conn.SetReadDeadline(time.Now().Add(pongWait)) //nolint:errcheck
		return nil
	})

	// Clients don't send anything meaningful; we just keep the pump alive
	// so we detect disconnects via read errors.
	for {
		_, _, err := c.conn.ReadMessage()
		if err != nil {
			if websocket.IsUnexpectedCloseError(err,
				websocket.CloseGoingAway, websocket.CloseAbnormalClosure) {
				c.hub.log.Debug("ws read error", zap.Error(err))
			}
			return
		}
	}
}

func (c *Client) writePump() {
	ticker := time.NewTicker(pingPeriod)
	defer func() {
		ticker.Stop()
		c.conn.Close()
	}()

	for {
		select {
		case message, ok := <-c.send:
			c.conn.SetWriteDeadline(time.Now().Add(writeWait)) //nolint:errcheck
			if !ok {
				c.conn.WriteMessage(websocket.CloseMessage, []byte{}) //nolint:errcheck
				return
			}
			if err := c.conn.WriteMessage(websocket.TextMessage, message); err != nil {
				return
			}
		case <-ticker.C:
			c.conn.SetWriteDeadline(time.Now().Add(writeWait)) //nolint:errcheck
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}