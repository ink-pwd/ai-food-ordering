---
paths:
  - app/Services/Repositories/CartRepository.php
---

# Repositories

## Current carts are active-only
Treat only status=active carts as current. A restaurant/session may keep multiple checked_out or expired historical carts, but PostgreSQL must enforce at most one active cart for that restaurant/session; get-or-create must recover unique insert races by returning the winning active cart.
