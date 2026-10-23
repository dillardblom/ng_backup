// SPDX-FileCopyrightText: 2026 Dillard Blom
// SPDX-License-Identifier: AGPL-3.0-or-later
import { getCanonicalLocale } from '@nextcloud/l10n'

// The locale of the user's Nextcloud settings (Personal info > Locale), not the browser's:
// a browser set to English would otherwise show 4:00 PM to someone with a 24-hour locale.
const locale = getCanonicalLocale()

// A number is a unix timestamp in seconds, a string an ISO 8601 date (snapshot times).
const toDate = (value) => typeof value === 'number' ? new Date(value * 1000) : new Date(value)

export const formatDateTime = (value) => toDate(value).toLocaleString(locale)

export const formatDate = (value) => toDate(value).toLocaleDateString(locale)

export function formatSize(bytes) {
	if (bytes === null || bytes === undefined) {
		return ''
	}
	const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
	let i = 0
	let b = bytes
	while (b >= 1024 && i < units.length - 1) {
		b /= 1024
		i++
	}
	return i === 0 ? `${b} B` : `${b.toFixed(1)} ${units[i]}`
}

/**
 * A time of day "HH:MM" in zone `from`, as "HH:MM" in zone `to`, at today's offsets.
 *
 * @param {string} hhmm the time
 * @param {string} from IANA zone it is in
 * @param {string} to IANA zone to show it in
 * @param {Date} now the day to convert on (today)
 * @return {string}
 */
export function toZone(hhmm, from, to, now = new Date()) {
	const [h, m] = hhmm.split(':').map(Number)
	const parts = (zone, date) => Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
		timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
	}).formatToParts(date).map((p) => [p.type, p.value]))
	const offsetAt = (at) => {
		const seen = parts(from, new Date(at))
		return Date.UTC(+seen.year, +seen.month - 1, +seen.day, +seen.hour, +seen.minute) - at
	}
	const today = parts(from, now)
	const wall = Date.UTC(+today.year, +today.month - 1, +today.day, h, m)
	// the offset at the wall time itself: on a day the clocks change it differs from the one at
	// midnight, so it is looked up a second time at the moment found first
	const at = wall - offsetAt(wall - offsetAt(wall))
	const there = parts(to, new Date(at))
	return `${there.hour}:${there.minute}`
}
