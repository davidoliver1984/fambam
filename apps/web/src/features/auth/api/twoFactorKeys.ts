export const twoFactorKeys = {
  all: ["two-factor"] as const,
  setup: () => [...twoFactorKeys.all, "setup"] as const,
  qrCode: () => [...twoFactorKeys.setup(), "qr-code"] as const,
};
