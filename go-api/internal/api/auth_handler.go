package api

import (
	"net/http"

	"github.com/gin-gonic/gin"
	"amberalert/internal/auth"
	"amberalert/internal/models"
	"amberalert/internal/repository"
	"go.uber.org/zap"
)

type authHandler struct {
	authSvc  *auth.Service
	userRepo *repository.UserRepo
	log      *zap.Logger
}

func newAuthHandler(authSvc *auth.Service, userRepo *repository.UserRepo, log *zap.Logger) *authHandler {
	return &authHandler{authSvc, userRepo, log}
}

// POST /api/v1/auth/register
func (h *authHandler) Register(c *gin.Context) {
	var req struct {
		Email    string `json:"email"     binding:"required,email"`
		Phone    string `json:"phone"     binding:"required"`
		FullName string `json:"full_name" binding:"required"`
		Password string `json:"password"  binding:"required,min=8"`
	}
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	hash, err := auth.HashPassword(req.Password)
	if err != nil {
		h.log.Error("hash password", zap.Error(err))
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}

	user := &models.User{
		Email:        req.Email,
		Phone:        req.Phone,
		FullName:     req.FullName,
		Role:         models.RolePublic,
		PasswordHash: hash,
		IsVerified:   false, // email verification flow handled separately
	}

	if err := h.userRepo.Create(c.Request.Context(), user); err != nil {
		h.log.Error("create user", zap.Error(err))
		c.JSON(http.StatusConflict, gin.H{"error": "email already registered"})
		return
	}

	access, refresh, err := h.authSvc.IssueTokens(user)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}

	c.JSON(http.StatusCreated, gin.H{
		"user":          user,
		"access_token":  access,
		"refresh_token": refresh,
	})
}

// POST /api/v1/auth/login
func (h *authHandler) Login(c *gin.Context) {
	var req struct {
		Email    string `json:"email"    binding:"required,email"`
		Password string `json:"password" binding:"required"`
	}
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	user, err := h.userRepo.GetByEmail(c.Request.Context(), req.Email)
	if err == repository.ErrNotFound || !auth.CheckPassword(user.PasswordHash, req.Password) {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "invalid credentials"})
		return
	}
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}

	go h.userRepo.UpdateLastLogin(c.Request.Context(), user.ID) //nolint:errcheck

	access, refresh, err := h.authSvc.IssueTokens(user)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}

	c.JSON(http.StatusOK, gin.H{
		"user":          user,
		"access_token":  access,
		"refresh_token": refresh,
	})
}

// POST /api/v1/auth/refresh
func (h *authHandler) Refresh(c *gin.Context) {
	var req struct {
		RefreshToken string `json:"refresh_token" binding:"required"`
	}
	if err := c.ShouldBindJSON(&req); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": err.Error()})
		return
	}

	claims, err := h.authSvc.VerifyAccess(req.RefreshToken)
	if err != nil {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "invalid refresh token"})
		return
	}

	user, err := h.userRepo.GetByEmail(c.Request.Context(), claims.Subject)
	if err != nil {
		c.JSON(http.StatusUnauthorized, gin.H{"error": "user not found"})
		return
	}

	access, refresh, err := h.authSvc.IssueTokens(user)
	if err != nil {
		c.JSON(http.StatusInternalServerError, gin.H{"error": "internal error"})
		return
	}

	c.JSON(http.StatusOK, gin.H{"access_token": access, "refresh_token": refresh})
}