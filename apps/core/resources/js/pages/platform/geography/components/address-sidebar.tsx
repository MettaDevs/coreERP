import { Button } from '@apperp/ui/button';
import { addressSections } from '../sections';
import type { Section } from '../types';

export function AddressSidebar({
    activeSection,
    onSelectSection,
}: {
    activeSection: Section;
    onSelectSection: (section: Section) => void;
}) {
    return (
        <aside className="shrink-0 overflow-x-auto border-b p-2 lg:w-56 lg:overflow-y-auto lg:border-r lg:border-b-0">
            <nav
                aria-label="Bagian pengaturan alamat"
                className="flex gap-1 lg:flex-col"
            >
                {addressSections.map((item) => (
                    <Button
                        key={item.key}
                        variant={
                            activeSection === item.key ? 'secondary' : 'ghost'
                        }
                        className="justify-start"
                        aria-current={
                            activeSection === item.key ? 'page' : undefined
                        }
                        onClick={() => onSelectSection(item.key)}
                    >
                        {item.label}
                    </Button>
                ))}
            </nav>
        </aside>
    );
}
