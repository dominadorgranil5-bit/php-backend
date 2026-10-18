/**
 * Example: src/lib/api.ts — a drop-in replacement for src/lib/supabase.ts.
 *
 * This is a STARTING POINT, not a full rewrite of your app. It shows the
 * shape each src/lib/*.ts file would take when talking to the new PHP
 * backend instead of Supabase. Copy the pattern into menu.ts, orders.ts,
 * staff.ts, payments.ts, and AuthContext.tsx, replacing the `supabase.from(...)`
 * calls with `api(...)` calls to the matching endpoint below.
 *
 * Auth model change: Supabase gave you a JWT session automatically stored
 * by the SDK. The PHP backend instead returns a plain bearer token from
 * /api/auth/login.php and /api/auth/register.php, which YOU store (e.g. in
 * AsyncStorage) and attach to every request's Authorization header.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';

// Set this to wherever you deploy php-backend/, e.g.
// https://your-domain.com/api  (matches APP_BASE_URL in .env)
const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL!;

const TOKEN_KEY = 'auth_token';

export async function getToken(): Promise<string | null> {
  return AsyncStorage.getItem(TOKEN_KEY);
}

export async function setToken(token: string | null): Promise<void> {
  if (token) await AsyncStorage.setItem(TOKEN_KEY, token);
  else await AsyncStorage.removeItem(TOKEN_KEY);
}

/** Thin fetch wrapper: adds the bearer token, JSON-encodes the body, throws on error. */
export async function api<T = unknown>(
  path: string,
  options: { method?: string; body?: unknown } = {}
): Promise<T> {
  const token = await getToken();

  const res = await fetch(`${API_BASE_URL}${path}`, {
    method: options.method ?? (options.body ? 'POST' : 'GET'),
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: options.body ? JSON.stringify(options.body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error((data as { error?: string }).error ?? `Request failed (${res.status})`);
  }
  return data as T;
}

// ---------------------------------------------------------------------
// Example equivalents of your existing lib functions:
// ---------------------------------------------------------------------

// menu.ts
// export function getAvailableMenuItems() {
//   return api<MenuItem[]>('/menu/available.php');
// }
// export function createMenuItem(item: Omit<MenuItem, 'id' | 'created_at'>) {
//   return api('/menu/create.php', { body: item });
// }

// orders.ts
// export function placeOrder(params: {...}) {
//   return api<Order>('/orders/place.php', { body: {
//     address: params.address, notes: params.notes, total: params.total,
//     payment_method: params.paymentMethod, items: params.items,
//   }});
// }
// export function getCustomerOrders() {
//   return api<Order[]>('/orders/customer.php');
// }

// AuthContext.tsx
// async function signIn(email: string, password: string) {
//   try {
//     const { token } = await api<{ token: string }>('/auth/login.php', { body: { email, password } });
//     await setToken(token);
//     return true;
//   } catch {
//     return false;
//   }
// }
// async function logout() {
//   await api('/auth/logout.php', { method: 'POST' });
//   await setToken(null);
// }

// payments.ts
// export async function createGcashCheckout(orderId: string) {
//   const { checkout_url } = await api<{ checkout_url: string }>(
//     '/payments/create-gcash-source.php',
//     { body: { order_id: orderId } }
//   );
//   return checkout_url;
// }
