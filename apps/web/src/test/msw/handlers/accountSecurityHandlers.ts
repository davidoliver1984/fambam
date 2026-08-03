import { http, HttpResponse } from "msw";

const apiBaseUrl = "http://localhost:8082";

export const accountSecurityHandlers = [
  http.get(
    `${apiBaseUrl}/sanctum/csrf-cookie`,
    () => new HttpResponse(null, { status: 204 }),
  ),
  http.put(`${apiBaseUrl}/api/user/password`, () =>
    HttpResponse.json(
      {
        message: "The given data was invalid.",
        errors: {
          password: ["Use a password that has not appeared in a breach."],
        },
      },
      { status: 422 },
    ),
  ),
];
