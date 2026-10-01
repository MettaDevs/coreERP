import * as React from 'react';
import type { Parameter, Section } from '../types';

export interface NavItemDef {
    key: Section;
    label: string;
    paramKey?: keyof Parameter;
}

export const navItems: NavItemDef[] = [
    { key: 'parameters', label: 'Parameters' },
    { key: 'addressFormat', label: 'Address Format' },
    { key: 'countries', label: 'Country / Region' },
    { key: 'provinces', label: 'State / Province', paramKey: 'use_province' },
    { key: 'regencies', label: 'County', paramKey: 'use_regency' },
    { key: 'cities', label: 'City', paramKey: 'use_regency' },
    { key: 'districts', label: 'District', paramKey: 'use_district' },
    { key: 'villages', label: 'Village', paramKey: 'use_village' },
    { key: 'streets', label: 'Street', paramKey: 'use_rt_rw' },
    { key: 'groupOfHouses', label: 'Group of Houses', paramKey: 'use_rt_rw' },
    { key: 'landPlots', label: 'Land Plots', paramKey: 'use_rt_rw' },
    { key: 'buildings', label: 'Group of Flats', paramKey: 'use_building' },
    {
        key: 'postalCodes',
        label: 'ZIP / Postal Codes',
        paramKey: 'use_postal_code',
    },
];

export function AddressSidebar({
    activeSection,
    onSelectSection,
    parameterConfig,
}: {
    activeSection: Section;
    onSelectSection: (key: Section) => void;
    parameterConfig?: Parameter | null;
}) {
    const visibleItems = React.useMemo(() => {
        return navItems.filter((item) => {
            // System-level configuration sections are always visible
            if (!item.paramKey) {
                return true;
            }

            // If no country parameter is configured yet, show all items
            if (!parameterConfig || !parameterConfig.country_code) {
                return true;
            }

            // Dynamically hide if parameter toggle is deactivated (false / '0' / 'false')
            const val = parameterConfig[item.paramKey];

            if (!val || val === '0' || val === 'false') {
                return false;
            }

            return true;
        });
    }, [parameterConfig]);

    return (
        <aside className="flex w-[195px] shrink-0 flex-col border-r border-[#edebe9] bg-[#f8f9fa] py-2 select-none">
            <nav className="flex flex-col space-y-[1px]">
                {visibleItems.map((item) => {
                    const isActive = activeSection === item.key;

                    return (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => onSelectSection(item.key)}
                            className={[
                                'relative flex w-full cursor-pointer items-center px-3.5 py-1.5 text-left text-[12.5px] transition-colors',
                                isActive
                                    ? 'bg-[#ffffff] font-semibold text-[#242424] before:absolute before:top-0 before:bottom-0 before:left-0 before:w-[3.5px] before:bg-[#0078d4]'
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
