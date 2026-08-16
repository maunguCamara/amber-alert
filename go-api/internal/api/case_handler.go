package api

import (
	"context"
	"fmt"
	"net/http"
	"strconv"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/google/uuid"
	"example.com/amberalert/internal/broadcast"
	"example.com/amberalert/internal/models"
	"example.com/amberalert/internal/repository"
	"example.com/amberalert/pkg/config"
	"example.com/amberalert/pkg/middleware"
	"go.uber.org/zap"
)

// kenyaBounds defines the valid coordinate bounding box for Kenya.
// Coordinates outside this box are rejected — they cannot represent a
// real location in Kenya and likely indicate a malformed or malicious request.
const (
	kenyaLatMin = -5.0
	kenyaLatMax =  5.0
	kenyaLngMin = 34.0
	kenyaLngMax = 42.0
)

type caseHandler struct {
	caseRepo      *repository.CaseRepo
	mediaRepo     *repository.MediaRepo
	broadcastRepo *repository.BroadcastRepo
	broadcaster   *broadcast.Service
	storage       *repository.Storage
	cfg           *config.Config
	log           *zap.Logger
}

func newCaseHandler(
	caseRepo *repository.CaseRepo,
	mediaRepo *repository.MediaRepo,
	broadcastRepo *repository.BroadcastRepo,
	broadcaster *broadcast.Service,
	storage *repository.Storage,
	cfg *config.Config,
	log *zap.Logger,
) *caseHandler {
	return &caseHandler{
		caseRepo:      caseRepo,
		mediaRepo:     mediaRepo,
		broadcastRepo: broadcastRepo,
		broadcaster:   broadcaster,
		storage:       storage,
		cfg:           cfg,
		log:           log,
	}
}

// POST /api/v1/cases
func (h *caseHandler) CreateCase(c *gin.Context) {
	var req struct {
		ChildName        string    `json:"child_name"         binding:"required,max=120"`
		Age              int       `json:"age"                binding:"required,min=0,max=17"`
		Gender           string    `json:"gender"             binding:"required,oneof=male female unknown"`
		HeightCM         float64   `json:"height_cm"`
		WeightKG         float64   `json:"weight_kg"`
		Complexion       string    `json:"complexion"         binding:"max=80"`
		Clothing         string    `json:"clothing"           binding:"required,max=255"`
		Distinctive      string    `json:"distinctive"        binding:"max=500"`
		Languages        []string  `json:"languages"`
		LastSeenArea     string    `json:"last_seen_area"     binding:"required,max=255"`
		County           string    `json:"county"             binding:"required,max=80"`
		SubCounty        string    `json:"sub_county"         binding:"max=120"`
		Lat              float64   `json:"lat"                binding:"required"`
		Lng              float64   `json:"lng"                binding:"required"`
		Description      string    `json:"description"        binding:"required,min=20,max=2000"`
		MissingSince     time.Time `json:"missing_since"      binding:"required"`
		CircumstanceType string    `json:"circumstance_type"  binding:"required,oneof=wandered abducted unknown"`
		ReporterType     string    `json:"reporter_type"      binding:"required,oneof=public police school ngo"`
		ContactPhone     string    `json:"contact_phone"      binding:"max=20"`
	}

	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	// FIX: validate Kenya coordinate bounds server-side
	if req.Lat < kenyaLatMin || req.Lat > kenyaLatMax {
		c.JSON(http.StatusBadRequest, gin.H{"error": fmt.Sprintf("lat %.4f is outside Kenya (%.1f..%.1f)", req.Lat, kenyaLatMin, kenyaLatMax)})
		return
	}
	if req.Lng < kenyaLngMin || req.Lng > kenyaLngMax {
		c.JSON(http.StatusBadRequest, gin.H{"error": fmt.Sprintf("lng %.4f is outside Kenya (%.1f..%.1f)", req.Lng, kenyaLngMin, kenyaLngMax)})
		return
	}

	// Reject future missing_since dates
	if req.MissingSince.After(time.Now()) {
		c.JSON(http.StatusBadRequest, gin.H{"error": "missing_since cannot be in the future"})
		return
	}

	ctx := c.Request.Context()
	userID := middleware.CurrentUserID(c)

	refNo, err := h.caseRepo.GenerateReferenceNo(ctx)
	if err != nil {
		h.log.Error("generate reference number", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}

	cas := &models.Case{
		ReferenceNo:      refNo,
		ChildName:        req.ChildName,
		Age:              req.Age,
		Gender:           models.Gender(req.Gender),
		HeightCM:         req.HeightCM,
		WeightKG:         req.WeightKG,
		Complexion:       req.Complexion,
		Clothing:         req.Clothing,
		Distinctive:      req.Distinctive,
		Languages:        req.Languages,
		LastSeenArea:     req.LastSeenArea,
		County:           req.County,
		SubCounty:        req.SubCounty,
		LastSeenLat:      req.Lat,
		LastSeenLng:      req.Lng,
		Description:      req.Description,
		MissingSince:     req.MissingSince,
		CircumstanceType: req.CircumstanceType,
		Status:           models.CaseStatusReview,
		ReporterID:       userID,
		ReporterType:     req.ReporterType,
		ContactPhone:     req.ContactPhone,
		CreatedBy:        userID,
	}

	if err := h.caseRepo.Create(ctx, cas); err != nil {
		h.log.Error("create case", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "failed to create case"})
		return
	}

	point := caseToGeoPoint(cas)

	// FIX: publish in a detached context so a cancelled request context
	// does not prevent the broadcast from reaching connected clients.
	go func() {
		pubCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		if err := h.broadcaster.Publish(pubCtx, repository.ChanCaseNew, &point); err != nil {
			h.log.Warn("broadcast publish failed", zap.Error(err))
		}
	}()

	h.log.Info("case created",
		zap.String("ref", cas.ReferenceNo),
		zap.String("county", cas.County),
	)
	c.JSON(http.StatusCreated, cas)
}

// GET /api/v1/cases/map
func (h *caseHandler) ListGeoPoints(c *gin.Context) {
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "500"))
	offset, _ := strconv.Atoi(c.DefaultQuery("offset", "0"))

	// Clamp limit to prevent excessively large responses
	if limit < 1 || limit > 1000 {
		limit = 500
	}

	county := c.Query("county")

	var statusPtr *models.CaseStatus
	if s := c.Query("status"); s != "" {
		st := models.CaseStatus(s)
		// Validate that the status value is a known enum value
		if !st.IsValid() {
			c.JSON(http.StatusBadRequest, gin.H{"error": "invalid status value"})
			return
		}
		statusPtr = &st
	}

	points, err := h.caseRepo.ListGeoPoints(c.Request.Context(), repository.CaseFilter{
		Status: statusPtr,
		County: county,
		Limit:  limit,
		Offset: offset,
	})
	if err != nil {
		h.log.Error("list geo points", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "failed to load map data"})
		return
	}

	c.JSON(http.StatusOK, gin.H{"data": points, "count": len(points)})
}

// GET /api/v1/cases
func (h *caseHandler) ListCases(c *gin.Context) {
	limit, _ := strconv.Atoi(c.DefaultQuery("limit", "20"))
	offset, _ := strconv.Atoi(c.DefaultQuery("offset", "0"))
	county := c.Query("county")

	var statusPtr *models.CaseStatus
	if s := c.Query("status"); s != "" {
		st := models.CaseStatus(s)
		if !st.IsValid() {
			c.JSON(http.StatusBadRequest, gin.H{"error": "invalid status value"})
			return
		}
		statusPtr = &st
	}

	points, err := h.caseRepo.ListGeoPoints(c.Request.Context(), repository.CaseFilter{
		Status: statusPtr,
		County: county,
		Limit:  limit,
		Offset: offset,
	})
	if err != nil {
		h.log.Error("list cases", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}
	c.JSON(http.StatusOK, gin.H{"data": points, "count": len(points)})
}

// GET /api/v1/cases/:id
func (h *caseHandler) GetCase(c *gin.Context) {
	id, err := uuid.Parse(c.Param("id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "invalid case id"})
		return
	}

	cas, err := h.caseRepo.GetByID(c.Request.Context(), id)
	if err == repository.ErrNotFound {
		c.JSON(http.StatusNotFound, gin.H{"error": "case not found"})
		return
	}
	if err != nil {
		h.log.Error("get case", zap.String("id", id.String()), zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}
	c.JSON(http.StatusOK, cas)
}

// PATCH /api/v1/cases/:id/status
func (h *caseHandler) UpdateStatus(c *gin.Context) {
	id, err := uuid.Parse(c.Param("id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "invalid case id"})
		return
	}

	var req struct {
		Status     string `json:"status"     binding:"required,oneof=active review resolved closed"`
		Resolution string `json:"resolution" binding:"max=500"`
	}
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	ctx := c.Request.Context()
	userID := middleware.CurrentUserID(c)
	status := models.CaseStatus(req.Status)

	if err := h.caseRepo.UpdateStatus(ctx, id, status, req.Resolution, userID); err != nil {
		h.log.Error("update status", zap.String("id", id.String()), zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "failed to update status"})
		return
	}

	cas, _ := h.caseRepo.GetByID(ctx, id)
	if cas != nil {
		point := caseToGeoPoint(cas)
		ch := repository.ChanCaseUpdated
		if status == models.CaseStatusResolved || status == models.CaseStatusClosed {
			ch = repository.ChanCaseResolved
		}
		go func() {
			pubCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			if err := h.broadcaster.Publish(pubCtx, ch, &point); err != nil {
				h.log.Warn("broadcast publish failed after status update", zap.Error(err))
			}
		}()
	}

	c.JSON(http.StatusOK, gin.H{"message": fmt.Sprintf("case marked as %s", status)})
}

// POST /api/v1/cases/:id/photos
func (h *caseHandler) UploadPhoto(c *gin.Context) {
	caseID, err := uuid.Parse(c.Param("id"))
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "invalid case id"})
		return
	}

	file, header, err := c.Request.FormFile("photo")
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "photo field required"})
		return
	}
	defer file.Close()

	data, err := repository.ReadAll(file, h.cfg.MaxUploadBytes)
	if err != nil {
		c.JSON(http.StatusRequestEntityTooLarge, gin.H{"error": "file too large"})
		return
	}

	result, err := h.storage.UploadPhoto(c.Request.Context(), caseID, header.Filename, data)
	if err != nil {
		// ErrUnsupportedFileType is a client error (400), everything else is server error (500)
		if err == repository.ErrUnsupportedFileType {
			c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
			return
		}
		h.log.Error("upload photo", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "upload failed"})
		return
	}

	isPrimary := c.PostForm("is_primary") == "true"
	m := &models.Media{
		CaseID:    caseID,
		URL:       result.URL,
		ThumbURL:  result.ThumbURL,
		MimeType:  result.MimeType,
		SizeBytes: result.Size,
		IsPrimary: isPrimary,
	}
	if err := h.mediaRepo.Insert(c.Request.Context(), m); err != nil {
		h.log.Error("insert media record", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "failed to save media record"})
		return
	}

	c.JSON(http.StatusCreated, m)
}

// DELETE /api/v1/cases/:id/photos/:photoId
func (h *caseHandler) DeletePhoto(c *gin.Context) {
	c.Status(http.StatusNoContent)
}

// POST /api/v1/webhooks/at/delivery
func (h *caseHandler) ATDeliveryReceipt(c *gin.Context) {
	messageID := c.PostForm("id")
	status := c.PostForm("status")

	if messageID == "" {
		c.JSON(http.StatusBadRequest, gin.H{"error": "missing id"})
		return
	}

	if status == "Success" {
		if err := h.broadcastRepo.MarkDelivered(c.Request.Context(), messageID); err != nil {
			h.log.Warn("mark delivered failed", zap.String("msg_id", messageID), zap.Error(err))
		}
	}
	c.Status(http.StatusOK)
}

// ── helpers ───────────────────────────────────────────────────────────────────

func caseToGeoPoint(c *models.Case) models.CaseGeoPoint {
	var thumb string
	if len(c.Photos) > 0 {
		thumb = c.Photos[0].ThumbURL
	}
	return models.CaseGeoPoint{
		ID:           c.ID,
		ReferenceNo:  c.ReferenceNo,
		ChildName:    c.ChildName,
		Age:          c.Age,
		Gender:       c.Gender,
		Status:       c.Status,
		County:       c.County,
		Lat:          c.LastSeenLat,
		Lng:          c.LastSeenLng,
		MissingSince: c.MissingSince,
		ThumbnailURL: thumb,
	}
}