document.addEventListener("DOMContentLoaded", function () {
  if (
    window.wc?.wcBlocksRegistry?.registerPaymentMethod &&
    window.wp?.element &&
    window.wc?.wcSettings
  ) {
    const settings = window.wc.wcSettings["foopay_data"] || {};
    const { createElement } = window.wp.element;

    window.wc.wcBlocksRegistry.registerPaymentMethod({
      name: "foopay",
      label: createElement("span", null, settings.title || "Foopay"),
      ariaLabel: settings.ariaLabel || "Foopay",
      supports: {
        features: ["products", "subscriptions", "default", "virtual"],
      },
      canMakePayment: () => Promise.resolve(true),
      content: createElement(
        "p",
        null,
        settings.description || "Pay with Foopay"
      ),
      edit: createElement("p", null, settings.description || "Pay with Foopay"),
      save: null,
    });

    console.log("[Foopay] registered in block checkout");
  }
});