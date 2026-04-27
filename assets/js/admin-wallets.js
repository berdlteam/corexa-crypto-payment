console.log("SPG admin-wallets.js loaded ✅ v2026-03-04-1");
jQuery(function ($) {
  const root = document.getElementById("corexa_wallets_root");
  if (!root) return;

  const tbody = document.getElementById("corexa_wallets_tbody");
  const addBtn = document.getElementById("corexa_add_wallet");
  if (!tbody || !addBtn) return;

  let wallets = [];
  let catalog = {};

  try { wallets = JSON.parse(root.getAttribute("data-wallets") || "[]"); } catch (e) { wallets = []; }
  try { catalog = JSON.parse(root.getAttribute("data-catalog") || "{}"); } catch (e) { catalog = {}; }

  function normNet(n) {
    return String(n || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
  }
  function normCoin(c) {
    return String(c || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
  }
  function needsTag(coin) {
    coin = normCoin(coin);
    return coin === "XRP" || coin === "XLM";
  }

  function escapeHtml(s) {
    return String(s ?? "").replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    }[m]));
  }
  function escapeAttr(s) { return escapeHtml(s); }

  function currencyOptionsHTML(selectedKey) {
    let html = `<option value="__CUSTOM__"${selectedKey === "__CUSTOM__" ? " selected" : ""}>Custom (enter coin + choose network)</option>`;

    Object.keys(catalog).forEach((k) => {
      const row = catalog[k] || [];
      const label = row[0] || k;
      const coin = row[1] || "";
      const net = row[2] || "";
      const dec = row[3] ?? "";
      const contract = row[4] ?? "";

      html += `<option
        value="${escapeAttr(k)}"
        data-coin="${escapeAttr(coin)}"
        data-network="${escapeAttr(net)}"
        data-decimals="${escapeAttr(dec)}"
        data-contract="${escapeAttr(contract)}"
        ${selectedKey === k ? " selected" : ""}
      >${escapeHtml(label)}</option>`;
    });

    return html;
  }

  function rowHTML(i, w) {
    const enabled = !!w.enabled;
    const coin = w.coin || "";
    const net = w.network || "";
    const currency_key = w.currency_key || "__CUSTOM__";
    const tag = w.tag || "";
    const contract = w.contract || "";
    const decimals = w.decimals || "";
    const address = w.address || "";

    const showCustom = (currency_key === "__CUSTOM__");
    const showTag = needsTag(coin);

    const nets = [
      "TRC20","ERC20","BEP20","POLYGON","BASE",
      "BTC","BCH","LTC","DOGE","DASH","DGB","MAZA","XVG",
      "TRX","XRP","XLM","SOL","ADA"
    ];

    return `
      <tr class="spg-wallet-row" data-index="${i}">
        <td>
          <input type="checkbox" name="corexa_wallets[${i}][enabled]" value="1"${enabled ? " checked" : ""} />
        </td>

        <td>
          <select class="spg-currency-select" name="corexa_wallets[${i}][currency_key]">
            ${currencyOptionsHTML(currency_key)}
          </select>

          <div class="spg-custom-wrap${showCustom ? "" : " is-hidden"}">
            <input type="text" class="spg-custom-coin" value="${escapeAttr(coin)}" placeholder="USDT / AAVE / ..." />
            <select class="spg-custom-net">
              ${nets.map((n) => `<option value="${n}"${normNet(net) === n ? " selected" : ""}>${n}</option>`).join("")}
            </select>
          </div>

          <div class="spg-tag-wrap${showTag ? "" : " is-hidden"}">
            <label class="spg-tag-label">Tag / Memo (required)</label>
            <input type="text" class="spg-tag" name="corexa_wallets[${i}][tag]" value="${escapeAttr(tag)}" placeholder="${normCoin(coin) === "XRP" ? "Destination Tag" : "Memo"}" />
          </div>

          <input type="hidden" class="spg-hidden-coin" name="corexa_wallets[${i}][coin]" value="${escapeAttr(coin)}">
          <input type="hidden" class="spg-hidden-net" name="corexa_wallets[${i}][network]" value="${escapeAttr(net)}">
          <input type="hidden" class="spg-adv-contract" name="corexa_wallets[${i}][contract]" value="${escapeAttr(contract)}">
          <input type="hidden" class="spg-adv-decimals" name="corexa_wallets[${i}][decimals]" value="${escapeAttr(decimals)}">
        </td>

        <td>
          <input type="text" class="spg-address-input" name="corexa_wallets[${i}][address]" value="${escapeAttr(address)}" placeholder="Wallet address" />
        </td>

        <td>
          <button type="button" class="button-link-delete spg-remove">remove</button>
        </td>
      </tr>
    `;
  }

  function syncHiddenFields(row) {
    const currencySel = row.querySelector(".spg-currency-select");
    const coinHidden = row.querySelector(".spg-hidden-coin");
    const netHidden = row.querySelector(".spg-hidden-net");
    const decHidden = row.querySelector(".spg-adv-decimals");
    const contractHidden = row.querySelector(".spg-adv-contract");

    const customWrap = row.querySelector(".spg-custom-wrap");
    const customCoin = row.querySelector(".spg-custom-coin");
    const customNet = row.querySelector(".spg-custom-net");

    const selected = currencySel ? currencySel.options[currencySel.selectedIndex] : null;
    const key = currencySel ? currencySel.value : "__CUSTOM__";

    if (key === "__CUSTOM__") {
      customWrap?.classList.remove("is-hidden");

      const c = customCoin ? customCoin.value : "";
      const n = customNet ? customNet.value : "";

      if (coinHidden) coinHidden.value = c;
      if (netHidden) netHidden.value = n;

      if (decHidden) decHidden.value = "";
      if (contractHidden) contractHidden.value = "";
    } else {
      customWrap?.classList.add("is-hidden");

      if (selected) {
        const c = selected.getAttribute("data-coin") || "";
        const n = selected.getAttribute("data-network") || "";
        const d = selected.getAttribute("data-decimals") || "";
        const ct = selected.getAttribute("data-contract") || "";

        if (coinHidden) coinHidden.value = c;
        if (netHidden) netHidden.value = n;
        if (decHidden) decHidden.value = d;
        if (contractHidden) contractHidden.value = ct;
      }
    }

    const coinVal = coinHidden ? coinHidden.value : "";
    const tagWrap = row.querySelector(".spg-tag-wrap");
    if (tagWrap) {
      if (needsTag(coinVal)) tagWrap.classList.remove("is-hidden");
      else tagWrap.classList.add("is-hidden");
    }
  }

  function getRowData(row) {
    // keep hidden fields aligned with UI before reading
    syncHiddenFields(row);

    const enabled = row.querySelector('input[type="checkbox"]')?.checked ? 1 : 0;
    const currency_key = row.querySelector(".spg-currency-select")?.value || "__CUSTOM__";

    const coin = row.querySelector(".spg-hidden-coin")?.value || "";
    const network = row.querySelector(".spg-hidden-net")?.value || "";

    const address = row.querySelector(".spg-address-input")?.value || "";
    const tag = row.querySelector(".spg-tag")?.value || "";

    const contract = row.querySelector(".spg-adv-contract")?.value || "";
    const decimals = row.querySelector(".spg-adv-decimals")?.value || "";

    return { enabled, currency_key, coin, network, address, tag, contract, decimals };
  }

  function syncWalletsFromDom() {
    const rows = tbody.querySelectorAll(".spg-wallet-row");
    const next = [];
    rows.forEach((row) => next.push(getRowData(row)));
    wallets = next;
  }

  function bindRow(row) {
    syncHiddenFields(row);

    const updateState = () => {
      const idx = parseInt(row.getAttribute("data-index") || "0", 10);
      if (!Number.isFinite(idx) || idx < 0) return;

      wallets[idx] = {
        ...(wallets[idx] || {}),
        ...getRowData(row),
      };
    };

    row.addEventListener("change", updateState);
    row.addEventListener("input", updateState);

    row.querySelector(".spg-remove")?.addEventListener("click", function (e) {
      e.preventDefault();

      syncWalletsFromDom();

      const idx = parseInt(row.getAttribute("data-index") || "0", 10);
      if (!isNaN(idx)) wallets.splice(idx, 1);

      render();
    });
  }

  function render() {
    // ✅ DO NOT sync here (it can overwrite wallets and lose state)
    tbody.innerHTML = "";

    wallets.forEach((w, i) => {
      tbody.insertAdjacentHTML("beforeend", rowHTML(i, w));
    });

    tbody.querySelectorAll(".spg-wallet-row").forEach((row) => bindRow(row));
  }

  addBtn.addEventListener("click", function (e) {
    e.preventDefault();

    // ✅ capture current typed inputs before adding new row
    syncWalletsFromDom();

    wallets.push({
      enabled: 1,
      currency_key: "__CUSTOM__",
      coin: "",
      network: "TRC20",
      address: "",
      tag: "",
      contract: "",
      decimals: ""
    });

    render();
  });

  render();
});