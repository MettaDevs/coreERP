import * as React from 'react';
import type { Section } from '../types';

const navItems: { key: Section; label: string }[] = [
    { key: 'parameters', label: 'Parameters' },
    { key: 'addressFormat', label: 'Address format' },
    { key: 'countries', label: 'Country/region' },
    { key: 'provinces', label: 'State/province' },
    { key: 'regencies', label: 'County' },
    { key: 'cities', label: 'City' },
    { key: 'districts', label: 'District' },
    { key: 'streets', label: 'Street' },
    { key: 'groupOfHouses', label: 'Group of houses' },
    { key: 'landPlots', label: 'Land plots' },
    { key: 'buildings', label: 'Group of flats' },
    { key: 'postalCodes', label: 'ZIP/postal codes' },
];

export function AddressSidebar({
    activeSection,
    onSelectSection,
}: {
    activeSection: Section;
    onSelectSection: (key: Section) => void;
}) {
    return (
        <aside className="w-[195px] shrink-0 border-r border-[#edebe9] bg-[#f8f9fa] select-none flex flex-col py-2">
            <nav className="flex flex-col space-y-[1px]">
                {navItems.map((item) => {
                    const isActive = activeSection === item.key;
                    return (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => onSelectSection(item.key)}
                            className={[
                                'relative w-full text-left px-3.5 py-1.5 text-[12.5px] transition-colors flex items-center cursor-pointer',
                                isActive
                                    ? 'bg-[#ffffff] text-[#242424] font-semibold before:absolute before:left-0 before:top-0 before:bottom-0 before:w-[3.5px] before:bg-[#0078d4]'
                                    : 'text-[#605e5c] hover:bg-[#f3f2f1] hover:text-[#242424]',
                            ].join(' ')}
                        >
                            <span className="truncate">{item.label}</span>
                        </button>
                    );
                })}
            </nav>
        </aside>
    );
}
