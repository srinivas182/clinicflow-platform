import { Head, Link } from '@inertiajs/react';
import { BadgeCheck } from 'lucide-react';
import { Logo } from '@/components/Logo';
import { Button } from '@/components/ui';

export default function Done({ name, address }: { name: string; address: string | null }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-5 bg-paper p-8 text-center">
            <Head title="You're set up" />
            <Logo />
            <BadgeCheck className="size-14 text-teal" aria-hidden="true" />
            <h1 className="text-2xl font-semibold">{name} is set up</h1>
            <p className="max-w-lg text-muted">
                Your workspace address is <b className="text-ink">{address}</b>. We're checking your registrations — usually within 2 hours on weekdays. You can sign in now
                to add staff, rooms and rosters; patients can book once you're verified.
            </p>
            <Link href="/login">
                <Button size="lg">Sign in</Button>
            </Link>
        </div>
    );
}
