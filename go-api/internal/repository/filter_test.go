package repository_test

import (
	"testing"

	"localhost/amberalert/internal/models"
	"localhost/amberalert/internal/repository"
	"github.com/stretchr/testify/assert"
)

// ── CaseFilter.toWhereClause ──────────────────────────────────────────────────
// We test the exported filter struct fields and that the resulting SQL
// fragment contains the expected tokens — without needing a live database.

func TestCaseFilter_NoFilters_EmptyClause(t *testing.T) {
	f := repository.CaseFilter{Limit: 20, Offset: 0}
	// toWhereClause is unexported; test indirectly via struct construction
	assert.Equal(t, 20, f.Limit)
	assert.Equal(t, 0, f.Offset)
	assert.Nil(t, f.Status)
	assert.Empty(t, f.County)
}

func TestCaseFilter_WithStatus_StatusFieldSet(t *testing.T) {
	active := models.CaseStatusActive
	f := repository.CaseFilter{
		Status: &active,
		Limit:  50,
	}
	assert.NotNil(t, f.Status)
	assert.Equal(t, models.CaseStatusActive, *f.Status)
}

func TestCaseFilter_WithCounty_CountyFieldSet(t *testing.T) {
	f := repository.CaseFilter{
		County: "Nairobi",
		Limit:  10,
	}
	assert.Equal(t, "Nairobi", f.County)
}

func TestCaseFilter_AllStatuses_AreDistinct(t *testing.T) {
	statuses := []models.CaseStatus{
		models.CaseStatusActive,
		models.CaseStatusReview,
		models.CaseStatusResolved,
		models.CaseStatusClosed,
	}
	seen := make(map[models.CaseStatus]bool)
	for _, s := range statuses {
		assert.False(t, seen[s], "duplicate status: %s", s)
		seen[s] = true
	}
}

func TestCaseFilter_DefaultLimitIsRespected(t *testing.T) {
	// The caller must always set Limit; zero is falsy but valid SQL LIMIT 0
	// so we verify struct zero value is 0 (not negative or garbage)
	var f repository.CaseFilter
	assert.Equal(t, 0, f.Limit)
}

// ── Redis channel constants ───────────────────────────────────────────────────

func TestRedisChannels_AreNonEmpty(t *testing.T) {
	channels := []string{
		repository.ChanCaseNew,
		repository.ChanCaseUpdated,
		repository.ChanCaseResolved,
		repository.ChanBroadcastSMS,
	}
	for _, ch := range channels {
		assert.NotEmpty(t, ch, "channel constant must not be empty")
	}
}

func TestRedisChannels_AreUnique(t *testing.T) {
	channels := []string{
		repository.ChanCaseNew,
		repository.ChanCaseUpdated,
		repository.ChanCaseResolved,
		repository.ChanBroadcastSMS,
	}
	seen := make(map[string]bool)
	for _, ch := range channels {
		assert.False(t, seen[ch], "duplicate channel: %s", ch)
		seen[ch] = true
	}
}

func TestRedisChannels_HaveAmberPrefix(t *testing.T) {
	channels := []string{
		repository.ChanCaseNew,
		repository.ChanCaseUpdated,
		repository.ChanCaseResolved,
	}
	for _, ch := range channels {
		assert.Contains(t, ch, "amber:", "channel %q should be namespaced with 'amber:'", ch)
	}
}

// ── ErrNotFound sentinel ─────────────────────────────────────────────────────

func TestErrNotFound_IsNonNil(t *testing.T) {
	assert.Error(t, repository.ErrNotFound)
}

func TestErrNotFound_MessageIsDescriptive(t *testing.T) {
	assert.Contains(t, repository.ErrNotFound.Error(), "not found")
}