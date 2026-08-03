import { setupServer } from "msw/node";

import { accountSecurityHandlers } from "./handlers/accountSecurityHandlers";

export const server = setupServer(...accountSecurityHandlers);
