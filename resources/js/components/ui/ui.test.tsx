import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Badge, Button, Ticket, TriageDot } from '@/components/ui';

describe('design system', () => {
    it('renders the queue ticket with an accessible label', () => {
        render(<Ticket number="A023" />);

        expect(screen.getByRole('img', { name: 'Ticket A023' })).toBeInTheDocument();
    });

    it('defaults buttons to type="button" so they never submit forms by accident', () => {
        render(<Button>Save</Button>);

        expect(screen.getByRole('button', { name: 'Save' })).toHaveAttribute('type', 'button');
    });

    it('describes triage colours in words for screen readers', () => {
        render(<TriageDot colour="red" />);

        expect(screen.getByText('Red — emergency')).toHaveClass('sr-only');
    });

    it('renders badge content', () => {
        render(<Badge tone="danger">Allergy</Badge>);

        expect(screen.getByText('Allergy')).toBeInTheDocument();
    });
});
