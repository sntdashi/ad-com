---
paths:
    - app/Models/TechnicianReview.php
---

# Models

## Keep technician ratings verified

Allow one review per service order and technician. Only the order-owning customer may rate after a completed, warranty, or closed state, and only technicians historically assigned to that order. Low scores are evaluation signals for supervisors, never automatic punishment.
