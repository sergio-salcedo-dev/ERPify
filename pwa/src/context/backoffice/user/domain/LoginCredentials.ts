/** The pair submitted to the session sign-in endpoint. The password is never stored. */
export interface LoginCredentials {
  email: string;
  password: string;
}
