import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Flash } from '@/components/Flash';
import { Field } from '@/components/form/Field';
import { Badge, Button, Card } from '@/components/ui';
import { AppShell } from '@/layouts/AppShell';

interface Session {
    id: number;
    staff: string;
    room: string | null;
    type: string;
    startsAt: string;
    endsAt: string;
    slotMinutes: number;
}

const time = (iso: string) => new Date(iso).toLocaleString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

export default function RostersIndex({
    weekStart,
    sessions,
    staff,
    rooms,
}: {
    weekStart: string;
    sessions: Session[];
    staff: { id: number; name: string; role: string }[];
    rooms: { id: number; name: string }[];
}) {
    const form = useForm({ staff_id: staff[0]?.id ?? 0, room_id: '' as string | number, starts_at: '', ends_at: '', slot_minutes: 15, session_type: 'in_person' });
    const room = useForm({ name: '' });
    const select = 'min-h-10 rounded-lg border border-[#CBD5D2] bg-white px-3 py-2 text-sm';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/rosters', { preserveScroll: true });
    };

    return (
        <AppShell active="Rosters">
            <Head title="Rosters" />
            <div className="mb-5 flex items-end gap-3">
                <div>
                    <h1 className="text-2xl font-semibold">Rosters</h1>
                    <p className="text-sm text-muted">Week of {weekStart}. Nobody can be in two sessions, and no room can hold two sessions, at once.</p>
                </div>
                <label className="ml-auto text-sm">
                    <span className="sr-only">Week</span>
                    <input type="date" value={weekStart} onChange={(e) => router.get('/rosters', { week: e.target.value })} className="rounded-lg border border-line px-3 py-2" />
                </label>
            </div>
            <Flash />
            <div className="grid grid-cols-3 gap-4">
                <Card title="This week" className="col-span-2">
                    {sessions.length === 0 && <p className="text-sm text-muted">No sessions yet.</p>}
                    <ul className="divide-y divide-[#EBF0EE] text-sm">
                        {sessions.map((s) => (
                            <li key={s.id} className="flex items-center gap-3 py-2.5">
                                <span className="w-40">{time(s.startsAt)}</span>
                                <span className="flex-1 font-medium">{s.staff}</span>
                                <span className="text-muted">{s.room ?? 'No room'}</span>
                                <Badge tone={s.type === 'telemedicine' ? 'network' : 'teal'}>{s.slotMinutes}-min slots</Badge>
                            </li>
                        ))}
                    </ul>
                </Card>
                <div className="flex flex-col gap-4">
                    <Card title="Add session">
                        <form onSubmit={submit} className="flex flex-col gap-3" noValidate>
                            <select aria-label="Staff member" className={select} value={form.data.staff_id} onChange={(e) => form.setData('staff_id', Number(e.target.value))}>
                                {staff.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                            <select aria-label="Room" className={select} value={form.data.room_id} onChange={(e) => form.setData('room_id', e.target.value)}>
                                <option value="">No room</option>
                                {rooms.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.name}
                                    </option>
                                ))}
                            </select>
                            <Field label="Starts" name="starts_at" type="datetime-local" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} error={form.errors.starts_at} />
                            <Field label="Ends" name="ends_at" type="datetime-local" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} error={form.errors.ends_at} />
                            <select aria-label="Slot length" className={select} value={form.data.slot_minutes} onChange={(e) => form.setData('slot_minutes', Number(e.target.value))}>
                                {[10, 15, 20, 30, 45, 60].map((m) => (
                                    <option key={m} value={m}>
                                        {m}-minute slots
                                    </option>
                                ))}
                            </select>
                            <Button type="submit" disabled={form.processing}>
                                Add to roster
                            </Button>
                        </form>
                    </Card>
                    <Card title="Rooms">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                room.post('/rooms', { preserveScroll: true, onSuccess: () => room.reset() });
                            }}
                            className="flex gap-2"
                        >
                            <Field label="New room" name="name" value={room.data.name} onChange={(e) => room.setData('name', e.target.value)} error={room.errors.name} className="flex-1" />
                            <Button type="submit" variant="secondary" className="self-end">
                                Add
                            </Button>
                        </form>
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {rooms.map((r) => (
                                <Badge key={r.id}>{r.name}</Badge>
                            ))}
                        </div>
                    </Card>
                </div>
            </div>
        </AppShell>
    );
}
