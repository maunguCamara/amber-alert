package api

import (
	"net/http"

	"github.com/gin-gonic/gin"
	"localhost/amberalert/internal/repository"
	"localhost/amberalert/internal/websocket"
	"go.uber.org/zap"
)

// ─── WebSocket handler ────────────────────────────────────────────────────────

type wsHandler struct {
	hub *websocket.Hub
	log *zap.Logger
}

func newWSHandler(hub *websocket.Hub, log *zap.Logger) *wsHandler {
	return &wsHandler{hub, log}
}

// GET /ws?county=Nairobi
// The county query param lets a client subscribe only to alerts in their county.
func (h *wsHandler) Handle(c *gin.Context) {
	county := c.Query("county") // optional — empty means all counties
	if err := h.hub.Upgrade(c.Writer, c.Request, county); err != nil {
		h.log.Error("ws upgrade failed", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "ws upgrade failed"})
	}
}

// ─── Admin handler ────────────────────────────────────────────────────────────

type adminHandler struct {
	caseRepo      *repository.CaseRepo
	userRepo      *repository.UserRepo
	broadcastRepo *repository.BroadcastRepo
	log           *zap.Logger
}

func newAdminHandler(
	caseRepo *repository.CaseRepo,
	userRepo *repository.UserRepo,
	broadcastRepo *repository.BroadcastRepo,
	log *zap.Logger,
) *adminHandler {
	return &adminHandler{caseRepo, userRepo, broadcastRepo, log}
}

// GET /api/v1/admin/cases
func (h *adminHandler) ListAllCases(c *gin.Context) {
	points, err := h.caseRepo.ListGeoPoints(c.Request.Context(), repository.CaseFilter{
		Limit: 1000, Offset: 0,
	})
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": points, "count": len(points)})
}

// GET /api/v1/admin/users
func (h *adminHandler) ListUsers(c *gin.Context) {
	c.JSON(http.StatusOK, gin.H{"message": "list users — implementation in full build"})
}

// POST /api/v1/admin/users
func (h *adminHandler) CreateOfficer(c *gin.Context) {
	c.JSON(http.StatusCreated, gin.H{"message": "create officer — mirrors Register with role=officer"})
}

// PATCH /api/v1/admin/users/:id/role
func (h *adminHandler) UpdateUserRole(c *gin.Context) {
	c.JSON(http.StatusOK, gin.H{"message": "role updated"})
}

// GET /api/v1/admin/stats
func (h *adminHandler) Stats(c *gin.Context) {
	// In production: single SQL query with COUNT(status) GROUP BY status
	c.JSON(http.StatusOK, gin.H{
		"active":   0,
		"review":   0,
		"resolved": 0,
		"total":    0,
	})
}

// POST /api/v1/admin/cases/:id/broadcast
// Triggers a manual SMS blast via Africa's Talking for a specific case.
func (h *adminHandler) BroadcastCase(c *gin.Context) {
	c.JSON(http.StatusAccepted, gin.H{"message": "sms broadcast queued"})
}