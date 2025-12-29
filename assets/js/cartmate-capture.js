console.log("CartMate JS loaded from PLUGIN cartmate-capture.js (generic selectors)");

jQuery(function ($) {
  console.log("CartMate: jQuery ready.");

  // Utility: find likely email/phone/name fields on checkout.
  function getCheckoutFields() {
    var $form = $("form.checkout");

    // Email candidates.
    var $email = $form.find("#billing_email");
    if ($email.length === 0) {
      $email = $form.find('input[name="billing_email"]');
    }
    if ($email.length === 0) {
      $email = $form.find('input[type="email"]');
    }

    // Phone candidates.
    var $phone = $form.find("#billing_phone");
    if ($phone.length === 0) {
      $phone = $form.find('input[name="billing_phone"]');
    }
    if ($phone.length === 0) {
      $phone = $form.find('input[type="tel"]');
    }

    // Name candidates.
    var $first = $form.find("#billing_first_name");
    if ($first.length === 0) {
      $first = $form.find('input[name="billing_first_name"]');
    }
    var $last = $form.find("#billing_last_name");
    if ($last.length === 0) {
      $last = $form.find('input[name="billing_last_name"]');
    }

    return {
      form: $form,
      email: $email,
      phone: $phone,
      first: $first,
      last: $last
    };
  }

  function collectAndSend(trigger) {
    if (typeof CartMateCapture === "undefined") {
      console.warn("CartMateCapture is undefined, aborting capture.");
      return;
    }

    var fields = getCheckoutFields();

    var email = fields.email.val();
    var phone = fields.phone.val() || "";
    var first = fields.first.val() || "";
    var last  = fields.last.val() || "";
    var name  = (first + " " + last).trim();

    console.log("CartMate: collectAndSend triggered by:", trigger, {
      email: email,
      phone: phone,
      first: first,
      last: last,
      name: name
    });

    if (!email) {
      console.warn("CartMate: no email yet, not sending.");
      return;
    }

    var payload = {
      action: "cartmate_capture",
      nonce: CartMateCapture.nonce,
      email: email,
      phone: phone,
      name: name
    };

    console.log("CartMate: sending AJAX payload:", payload);

    $.post(CartMateCapture.ajax_url, payload)
      .done(function (resp) {
        console.log("[CartMate] capture AJAX success:", resp);
      })
      .fail(function (jqXHR, textStatus, errorThrown) {
        console.warn("[CartMate] capture AJAX failed:", textStatus, errorThrown);
      });
  }

  // Attach listeners using GENERIC selectors inside the checkout form.
  $(document).on(
    "blur",
    "form.checkout input[type='email'], form.checkout input[type='tel'], form.checkout input[name='billing_email'], form.checkout input[name='billing_phone']",
    function () {
      collectAndSend("blur:" + (this.id || this.name || "unknown"));
    }
  );

  // Backup: capture when checkout is submitted.
  $(document).on("checkout_place_order", function () {
    collectAndSend("checkout_place_order");
  });

  console.log("CartMate: generic event handlers attached.");
});
