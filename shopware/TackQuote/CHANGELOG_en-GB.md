# 0.3.0

* Added quote-only (B2B catalog) mode. A store can be switched so that adding to
  the cart is refused and customers request a quote instead — for every customer,
  for signed-out visitors only, or for chosen customer groups.
* The refusal is enforced on the server, in a decorated `CartItemAddRoute`, so
  the Store API and the storefront controllers are all covered rather than only
  the button being hidden.
* A cart that was already filled when the mode was switched on is blocked from
  ordering by a cart validator. Removing items still works, so a shopper is never
  stranded with a basket they can neither order nor empty.
* Administrators and API sources stay exempt, so the store remains testable while
  it is closed to customers.

# 0.2.0

* Storefront "Request a Quote" button.
