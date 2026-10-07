import { Head, router, useForm } from "@inertiajs/react";
import { useState } from "react";
import { Flash } from "@/components/Flash";
import { Badge, Button, Card } from "@/components/ui";
import { AppShell } from "@/layouts/AppShell";

interface Member {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    role: string;
    roleLabel: string;
    active: boolean;
    expires: string | null;
    authenticator: boolean;
    branches: number[];
    lastSignIn: string | null;
    self: boolean;
    owner: boolean;
}
interface Props {
    members: Member[];
    invitations: {
        id: number;
        name: string;
        contact: string;
        role: string;
        expired: boolean;
        expires: string;
    }[];
    roles: { value: string; label: string }[];
    branches: { id: number; name: string }[];
}

/** Practice → Staff: invite people, set roles and branches, suspend or restore access. */
export default function StaffIndex({
    members,
    invitations,
    roles,
    branches,
}: Props) {
    const invite = useForm({
        name: "",
        email: "",
        phone: "",
        role: roles[0]?.value ?? "",
        branches: [] as number[],
    });
    const [editing, setEditing] = useState<{
        id: number;
        role: string;
        branches: number[];
    } | null>(null);
    const input = "rounded-md border border-line px-2 py-1 text-sm";
    const branchName = (id: number) =>
        branches.find((b) => b.id === id)?.name ?? "";
    const toggle = (list: number[], id: number) =>
        list.includes(id) ? list.filter((x) => x !== id) : [...list, id];

    return (
        <AppShell active="Staff">
            <Head title="Staff" />
            <h1 className="mb-1 text-2xl font-semibold">Staff</h1>
            <p className="mb-5 text-sm text-muted">
                Invite your team, choose what each person can do, and suspend
                access when someone leaves. Changes ask you to confirm it’s you.
            </p>
            <Flash />
            <Card title="Invite someone" className="mb-4">
                <div className="flex flex-wrap items-end gap-2 text-sm">
                    <label>
                        Name
                        <br />
                        <input
                            aria-label="Name"
                            className={input}
                            value={invite.data.name}
                            onChange={(e) =>
                                invite.setData("name", e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Email
                        <br />
                        <input
                            aria-label="Email"
                            type="email"
                            className={input}
                            value={invite.data.email}
                            onChange={(e) =>
                                invite.setData("email", e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Mobile
                        <br />
                        <input
                            aria-label="Mobile"
                            inputMode="tel"
                            className={input}
                            value={invite.data.phone}
                            onChange={(e) =>
                                invite.setData("phone", e.target.value)
                            }
                        />
                    </label>
                    <label>
                        Role
                        <br />
                        <select
                            aria-label="Role"
                            className={input}
                            value={invite.data.role}
                            onChange={(e) =>
                                invite.setData("role", e.target.value)
                            }
                        >
                            {roles.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    {branches.length > 1 && (
                        <fieldset className="flex flex-wrap items-center gap-2">
                            <legend className="sr-only">Branches</legend>
                            {branches.map((b) => (
                                <label
                                    key={b.id}
                                    className="flex items-center gap-1"
                                >
                                    <input
                                        type="checkbox"
                                        className="accent-teal"
                                        checked={invite.data.branches.includes(
                                            b.id,
                                        )}
                                        onChange={() =>
                                            invite.setData(
                                                "branches",
                                                toggle(
                                                    invite.data.branches,
                                                    b.id,
                                                ),
                                            )
                                        }
                                    />{" "}
                                    {b.name}
                                </label>
                            ))}
                        </fieldset>
                    )}
                    <Button
                        size="sm"
                        disabled={invite.processing || !invite.data.name}
                        onClick={() =>
                            invite.post("/staff/invitations", {
                                preserveScroll: true,
                                onSuccess: () => invite.reset(),
                            })
                        }
                    >
                        Send invitation
                    </Button>
                </div>
                {Object.values(invite.errors)[0] && (
                    <p className="mt-2 text-xs text-status-danger">
                        {Object.values(invite.errors)[0]}
                    </p>
                )}
            </Card>
            {invitations.length > 0 && (
                <Card title="Waiting to accept" className="mb-4">
                    {invitations.map((i) => (
                        <div
                            key={i.id}
                            className="flex flex-wrap items-center gap-2 py-1 text-sm"
                        >
                            <span className="flex-1">
                                {i.name} · {i.contact} · {i.role}
                            </span>
                            {i.expired ? (
                                <Badge tone="warning">expired</Badge>
                            ) : (
                                <span className="text-xs text-muted">
                                    until {i.expires}
                                </span>
                            )}
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        `/staff/invitations/${i.id}/resend`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Send again
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    window.confirm(
                                        "Withdraw this invitation?",
                                    ) &&
                                    router.post(
                                        `/staff/invitations/${i.id}/revoke`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Withdraw
                            </Button>
                        </div>
                    ))}
                </Card>
            )}
            <Card title={`Team (${members.length})`}>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs text-muted">
                                <th className="py-1">Name</th>
                                <th>Role</th>
                                <th>Branches</th>
                                <th>Sign-in</th>
                                <th>Status</th>
                                <th>
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((m) => (
                                <tr
                                    key={m.id}
                                    className="border-t border-line-soft align-top"
                                >
                                    <td className="py-2">
                                        {m.name}
                                        {m.self && (
                                            <span className="text-xs text-muted">
                                                {" "}
                                                (you)
                                            </span>
                                        )}
                                        <span className="block text-xs text-muted">
                                            {m.email ?? m.phone}
                                        </span>
                                    </td>
                                    <td className="py-2">
                                        {editing?.id === m.id ? (
                                            <select
                                                aria-label={`Role for ${m.name}`}
                                                className={input}
                                                value={editing.role}
                                                onChange={(e) =>
                                                    setEditing({
                                                        ...editing,
                                                        role: e.target.value,
                                                    })
                                                }
                                            >
                                                {roles.map((r) => (
                                                    <option
                                                        key={r.value}
                                                        value={r.value}
                                                    >
                                                        {r.label}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            m.roleLabel
                                        )}
                                        {m.expires && (
                                            <span className="block text-xs text-muted">
                                                until {m.expires}
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2">
                                        {editing?.id === m.id
                                            ? branches.map((b) => (
                                                  <label
                                                      key={b.id}
                                                      className="mr-2 inline-flex items-center gap-1 text-xs"
                                                  >
                                                      <input
                                                          type="checkbox"
                                                          className="accent-teal"
                                                          checked={editing.branches.includes(
                                                              b.id,
                                                          )}
                                                          onChange={() =>
                                                              setEditing({
                                                                  ...editing,
                                                                  branches:
                                                                      toggle(
                                                                          editing.branches,
                                                                          b.id,
                                                                      ),
                                                              })
                                                          }
                                                      />{" "}
                                                      {b.name}
                                                  </label>
                                              ))
                                            : m.branches
                                                  .map(branchName)
                                                  .join(", ") || (
                                                  <span className="text-muted">
                                                      All
                                                  </span>
                                              )}
                                    </td>
                                    <td className="py-2 text-xs">
                                        {m.authenticator ? (
                                            <Badge tone="success">
                                                authenticator
                                            </Badge>
                                        ) : (
                                            <span className="text-muted">
                                                code by message
                                            </span>
                                        )}
                                        <span className="block text-muted">
                                            {m.lastSignIn
                                                ? `last ${m.lastSignIn}`
                                                : "never signed in"}
                                        </span>
                                    </td>
                                    <td className="py-2">
                                        {m.active ? (
                                            <Badge tone="success">active</Badge>
                                        ) : (
                                            <Badge tone="danger">
                                                suspended
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">
                                        {!m.self &&
                                            !m.owner &&
                                            (editing?.id === m.id ? (
                                                <span className="inline-flex gap-1">
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(
                                                                `/staff/${m.id}`,
                                                                {
                                                                    role: editing.role,
                                                                    branches:
                                                                        editing.branches,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess:
                                                                        () =>
                                                                            setEditing(
                                                                                null,
                                                                            ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Save
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setEditing(null)
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                </span>
                                            ) : (
                                                <span className="inline-flex gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        onClick={() =>
                                                            setEditing({
                                                                id: m.id,
                                                                role: m.role,
                                                                branches:
                                                                    m.branches,
                                                            })
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                    {m.active ? (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                window.confirm(
                                                                    `Suspend ${m.name}? They will lose access straight away.`,
                                                                ) &&
                                                                router.post(
                                                                    `/staff/${m.id}/suspend`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Suspend
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/staff/${m.id}/reactivate`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Restore
                                                        </Button>
                                                    )}
                                                </span>
                                            ))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
        </AppShell>
    );
}
