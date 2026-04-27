(function ($) {
  "use strict";

  // ---------------- PAYMENT TIMER (legacy; safe if timer HTML is not present) ----------------
  var spgTimerInterval = null;
  var spgTimerEndsAt = null;

  function pad2(n) {
    n = Number(n) || 0;
    return n < 10 ? "0" + n : "" + n;
  }

  function formatMMSS(sec) {
    sec = Math.max(0, Math.floor(sec || 0));
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return pad2(m) + ":" + pad2(s);
  }

  function setPlaceOrderDisabled(disabled) {
    var $btn = $("#place_order");
    if (!$btn.length) return;
    $btn.prop("disabled", !!disabled);
    $btn.toggleClass("disabled", !!disabled);
  }

  function renderTimer(secLeft) {
    var $wrap = $("#corexa_timer_wrap");
    if (!$wrap.length) return;

    var $hm = $wrap.find(".mcc_payment_timer .hours_minutes");
    if ($hm.length) $hm.text(formatMMSS(secLeft));
  }

  function showTimerExpired() {
    renderTimer(0);
    setPlaceOrderDisabled(true);
  }

  function stopPaymentTimer() {
    if (spgTimerInterval) {
      clearInterval(spgTimerInterval);
      spgTimerInterval = null;
    }
    spgTimerEndsAt = null;
    setPlaceOrderDisabled(false);
    renderTimer(0);
  }

  function startPaymentTimer() {
    if (!window.COREXA_DATA || !COREXA_DATA.timer_enabled) return;

    var mins = parseInt(COREXA_DATA.timer_minutes, 10);
    if (!isFinite(mins) || mins <= 0) return;

    // Avoid double intervals when Woo triggers updated_checkout many times
    if (spgTimerInterval) {
      clearInterval(spgTimerInterval);
      spgTimerInterval = null;
    }

    // If we already started a timer, keep the same end time
    if (!spgTimerEndsAt) {
      spgTimerEndsAt = Date.now() + mins * 60 * 1000;
      renderTimer(mins * 60);
    }

    setPlaceOrderDisabled(false);

    spgTimerInterval = setInterval(function () {
      var leftMs = spgTimerEndsAt - Date.now();
      var leftSec = Math.ceil(leftMs / 1000);

      if (leftSec <= 0) {
        stopPaymentTimer();
        showTimerExpired();
        return;
      }

      renderTimer(leftSec);
    }, 1000);
  }

  function isCdpSelected() {
    return (
      $('input[name="payment_method"]:checked').val() === "corexa_crypto_manual"
    );
  }

  function updateTimerVisibility() {
    var $wrap = $("#corexa_timer_wrap");
    if (!$wrap.length) return; // timer not rendered on checkout anymore

    var hasWallet = !!$("#corexa_wallet_choice").val();
    if (
      isCdpSelected() &&
      hasWallet &&
      window.COREXA_DATA &&
      COREXA_DATA.timer_enabled
    ) {
      $wrap.show();
      startPaymentTimer();
    } else {
      $wrap.hide();
      stopPaymentTimer();
    }
  }

  // ---------------- helpers ----------------
  function wallets() {
    return window.COREXA_DATA && Array.isArray(COREXA_DATA.wallets)
      ? COREXA_DATA.wallets
      : [];
  }

  function getWallet(key) {
    key = String(key || "");
    var list = wallets();
    for (var i = 0; i < list.length; i++) {
      if (String(list[i] && list[i].key) === key) return list[i];
    }
    return null;
  }

  function rates() {
    return window.COREXA_DATA && COREXA_DATA.rates_usd
      ? COREXA_DATA.rates_usd
      : {};
  }

  function getUsdTotalRaw() {
    var v = ($("#corexa_order_total_raw").val() || "").toString().trim();
    var n = parseFloat(v.replace(",", "."));
    return isFinite(n) ? n : 0;
  }

  function fmtCoin(x) {
    var s = (Math.round((Number(x) || 0) * 1e8) / 1e8).toFixed(8);
    return s.replace(/\.?0+$/, "");
  }

  function isRateCoin(coin) {
    coin = String(coin || "")
      .toUpperCase()
      .trim();
    return (
      [
        "BTC",
        "LTC",
        "BCH",
        "DOGE",
        "DASH",
        "DGB",
        "ETH",
        "BNB",
        "MATIC",
        "SOL",
        "MAZA",
      ].indexOf(coin) !== -1
    );
  }

  function legacyCopy(text) {
    var ta = document.createElement("textarea");
    ta.value = text;
    ta.setAttribute("readonly", "");
    ta.style.position = "fixed";
    ta.style.left = "-9999px";
    ta.style.top = "0";
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
      ta.setSelectionRange(0, ta.value.length);
    } catch (e) {}
    try {
      document.execCommand("copy");
    } catch (e2) {}
    document.body.removeChild(ta);
    return Promise.resolve();
  }

  function copyText(text) {
    text = (text || "").toString().trim();
    if (!text) return Promise.resolve(false);

    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard
        .writeText(text)
        .then(function () {
          return true;
        })
        .catch(function () {
          return legacyCopy(text).then(function () {
            return true;
          });
        });
    }

    return legacyCopy(text).then(function () {
      return true;
    });
  }

  function renderUsdOnly(usd) {
    var u = isFinite(usd) && usd > 0 ? usd : getUsdTotalRaw();
    return '<span style="color:gray;">amount: USD </span> ' + u.toFixed(2);
  }

  function renderUsdPlusEstimate(usd, coin, est) {
    return (
      '<span style="color:gray;">amount: USD </span> ' +
      usd.toFixed(2) +
      '<br><span style="color:gray;">≈ </span> ' +
      fmtCoin(est) +
      " " +
      coin
    );
  }

  function safeImgSrc(src) {
    src = (src || "").toString().trim();
    if (!src) return "";
    // allow data:image or http(s)
    if (src.indexOf("data:image/") === 0) return src;
    if (src.indexOf("http://") === 0 || src.indexOf("https://") === 0)
      return src;
    return "";
  }

  function renderIconsForWallet(w) {
    var iconsOn = !!(window.COREXA_DATA && COREXA_DATA.icons_enabled);

    var $iconTop = $("#corexa_coin_icon");
    var $iconPrev = $("#corexa_preview_icon");
    var $labelPrev = $("#corexa_preview_label");

    function hideIcon($el) {
      if (!$el || !$el.length) return;
      $el.addClass("is-hidden").hide().attr("src", "");
    }

    if (!iconsOn) {
      hideIcon($iconTop);
      hideIcon($iconPrev);
      if ($labelPrev.length) $labelPrev.text("");
      return;
    }

    if (!w || !w.icon) {
      hideIcon($iconTop);
      hideIcon($iconPrev);
      if ($labelPrev.length) $labelPrev.text("");
      return;
    }

    if ($iconTop.length) {
      $iconTop.removeClass("is-hidden").attr("src", safeImgSrc(w.icon)).show();
    }

    if ($iconPrev.length) {
      $iconPrev.removeClass("is-hidden").attr("src", safeImgSrc(w.icon)).show();
    }

    if ($labelPrev.length) {
      $labelPrev.text(((w.coin || "") + " " + (w.network || "")).trim());
    }
  }

  function showWallet(w) {
    var $qr = $("#corexa_qr");

    // apply QR display size
    if ($qr.length && window.COREXA_DATA && COREXA_DATA.qr_size_px) {
      var px = parseInt(COREXA_DATA.qr_size_px, 10);
      if (!isFinite(px) || px <= 0) px = 220;
      $qr.css("max-width", String(px) + "px");
      $qr.css("height", "auto");
    }

    // icons
    renderIconsForWallet(w);

    if (!w) {
      $("#corexa_preview").hide();
      if ($qr.length) $qr.attr("src", "");

      $("#corexa_network").text("");
      $("#corexa_address").html('<span style="color:gray;">address: </span>');
      $("#corexa_note_row").text("");

      $("#corexa_wallet_coin").val("");
      $("#corexa_wallet_network").val("");
      $("#corexa_wallet_address").val("");
      $("#corexa_wallet_qr").val("");
      $("#corexa_wallet_contract").val("");
      $("#corexa_wallet_decimals").val("");
      $("#corexa_wallet_tag").val("");

      var usd0 = getUsdTotalRaw();
      $("#corexa_amount").html(renderUsdOnly(usd0));
      $("#corexa_amount").data("usd", usd0.toFixed(2));
      $("#corexa_amount").data("coin", "");

      updateTimerVisibility();
      return;
    }

    var usd = getUsdTotalRaw();
    var coin = (w.coin || "").toString().toUpperCase().trim();

    $("#corexa_amount").html(renderUsdOnly(usd));
    $("#corexa_amount").data("usd", usd.toFixed(2));
    $("#corexa_amount").data("coin", "");

    if (usd > 0 && isRateCoin(coin)) {
      var r = rates();
      var price = parseFloat(r[coin]);
      if (isFinite(price) && price > 0) {
        var est = usd / price;
        $("#corexa_amount").html(renderUsdPlusEstimate(usd, coin, est));
        $("#corexa_amount").data("coin", fmtCoin(est) + " " + coin);
      }
    }

    $("#corexa_preview").show();
    if ($qr.length) $qr.attr("src", safeImgSrc(w.qr || ""));

    $("#corexa_network").text(w.network || "");
    $("#corexa_address").html(
      '<span style="color:gray;">address: </span>' + (w.address || ""),
    );
    $("#corexa_note_row").text(w.note || "");

    // These hidden inputs are for compatibility only (server ignores them)
    $("#corexa_wallet_coin").val(w.coin || "");
    $("#corexa_wallet_network").val(w.network || "");
    $("#corexa_wallet_address").val(w.address || "");
    $("#corexa_wallet_qr").val(w.qr || "");
    $("#corexa_wallet_contract").val(w.contract || "");
    $("#corexa_wallet_decimals").val(w.decimals || "");
    $("#corexa_wallet_tag").val(w.tag || "");

    updateTimerVisibility();
  }

  function ensurePrimaryIsShown() {
    if (!isCdpSelected()) return;

    var $input = $("#corexa_wallet_choice");
    if (!$input.length) return;

    var key = ($input.val() || "").toString().trim();
    var w = key ? getWallet(key) : null;

    if (w) {
      var $item = $('#corexa_wallet_picker_menu .spg-wallet-picker-item[data-key="' + key.replace(/"/g, '\\"') + '"]');
      var label = ($item.attr("data-label") || "").toString().trim();

      if (label) {
        $("#corexa_selected_coin_label").text(label);
      }

      $("#corexa_wallet_picker_menu .spg-wallet-picker-item").removeClass("is-selected");
      $item.addClass("is-selected");

      var $selectedIcon = $("#corexa_selected_coin_icon");
      if ($selectedIcon.length) {
        if (w.icon) {
          $selectedIcon
            .attr("src", safeImgSrc(w.icon))
            .removeClass("is-hidden")
            .show();
        } else {
          $selectedIcon
            .attr("src", "")
            .addClass("is-hidden")
            .hide();
        }
      }
    }

    showWallet(w);
  }

  function dedupeWalletPicker() {
    var $items = $("#corexa_wallet_picker_menu .spg-wallet-picker-item");
    if (!$items.length) return;

    var seen = {};
    $items.each(function () {
      var v = String($(this).attr("data-key") || "");
      if (!v) return;

      if (seen[v]) {
        $(this).remove();
      } else {
        seen[v] = true;
      }
    });
  }

  // ---------------- Events ----------------
  $(document).on("click", "#corexa_wallet_picker_toggle", function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $picker = $("#corexa_wallet_picker");
    var isOpen = $picker.hasClass("is-open");

    $picker.toggleClass("is-open", !isOpen);
    $(this).attr("aria-expanded", !isOpen ? "true" : "false");
  });

  $(document).on(
    "click",
    "#corexa_wallet_picker_menu .spg-wallet-picker-item",
    function (e) {
      e.preventDefault();

      var $item = $(this);
      var key = ($item.attr("data-key") || "").toString();
      var label = ($item.attr("data-label") || "").toString();
      var icon = ($item.attr("data-icon") || "").toString();

      $("#corexa_wallet_choice").val(key).trigger("change");
      $("#corexa_selected_coin_label").text(label);

      var $selectedIcon = $("#corexa_selected_coin_icon");
      if ($selectedIcon.length) {
        if (icon) {
          $selectedIcon
            .attr("src", safeImgSrc(icon))
            .removeClass("is-hidden")
            .show();
        } else {
          $selectedIcon.attr("src", "").addClass("is-hidden").hide();
        }
      }

      $("#corexa_wallet_picker_menu .spg-wallet-picker-item").removeClass(
        "is-selected",
      );
      $item.addClass("is-selected");

      $("#corexa_wallet_picker").removeClass("is-open");

      showWallet(getWallet(key));
    },
  );

  $(document).on("change", "#corexa_wallet_choice", function () {
    showWallet(getWallet(this.value));
  });

  $(document).on("click", function (e) {
    var $target = $(e.target);
    if (!$target.closest("#corexa_wallet_picker").length) {
      $("#corexa_wallet_picker").removeClass("is-open");
    }
  });

  $(document).on("click", "#corexa_copy_address", function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $icon = $(this);
    var addr = ($("#corexa_wallet_address").val() || "").toString().trim();

    copyText(addr).then(function (ok) {
      if (!ok) return;
      $icon.css("opacity", "0.4");
      setTimeout(function () {
        $icon.css("opacity", "");
      }, 250);
    });
  });

  $(document).on("click", "#corexa_copy_amount", function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $icon = $(this);
    var coinVal = ($("#corexa_amount").data("coin") || "").toString().trim();
    var usdVal = ($("#corexa_amount").data("usd") || "").toString().trim();
    var toCopy = coinVal ? coinVal : usdVal;

    copyText(toCopy).then(function (ok) {
      if (!ok) return;
      $icon.css("opacity", "0.4");
      setTimeout(function () {
        $icon.css("opacity", "");
      }, 250);
    });
  });

  $(document).on("change", 'input[name="payment_method"]', function () {
    updateTimerVisibility();
    setTimeout(ensurePrimaryIsShown, 0);
    setTimeout(ensurePrimaryIsShown, 150);
  });

  // Init
  $(function () {
    // prevent broken icon showing
    var icon = document.getElementById("corexa_selected_coin_icon");
        if (icon) {
      icon.onerror = function () {
        icon.classList.add("is-hidden");
        try {
          icon.style.display = "none";
        } catch (e) {}
      };
    }

     dedupeWalletPicker();
    updateTimerVisibility();
    setTimeout(ensurePrimaryIsShown, 0);
    setTimeout(ensurePrimaryIsShown, 150);
    setTimeout(ensurePrimaryIsShown, 400);
  });

  // Woo refresh (shipping, totals, etc.)
  $(document.body).on("updated_checkout", function () {
     dedupeWalletPicker();
    updateTimerVisibility();
    setTimeout(ensurePrimaryIsShown, 0);
    setTimeout(ensurePrimaryIsShown, 150);
  });
})(jQuery);
