import {
    CollapsibleSection,
    CollapsibleSectionGroup,
} from '@apperp/ui/collapsible-section';
import { AdditionalFilters } from './AdditionalFilters';
import { ReportFilterBar } from './ReportFilterBar';
import type { ReportFilterDefinition } from './reportFilterProfiles';
import {
    AssetFilter,
    ChoiceFilter,
    DateFilter,
    DepreciationBookFilter,
    MasterFilter,
    MonthFilter,
    OptionsFilter,
} from './ReportFilters';
import { showReportControl } from './reportFilterState';
import type { ReportFilterState } from './useReportData';

function ReportFilterControl({
    definition,
    state,
}: {
    definition: ReportFilterDefinition;
    state: ReportFilterState;
}) {
    switch (definition.kind) {
        case 'date':
            return (
                <DateFilter
                    label={definition.label}
                    {...state.bindFilter(definition.key)}
                />
            );
        case 'month':
        case 'period':
            return (
                <MonthFilter
                    label={definition.label}
                    {...state.bindFilter(definition.key)}
                />
            );
        case 'master':
            return (
                <MasterFilter
                    {...state.bindMultiFilter(definition.key)}
                    resource={definition.resource}
                    label={definition.label}
                    unavailable={definition.unavailable}
                />
            );
        case 'asset':
            return <AssetFilter {...state.bindFilter(definition.key)} />;
        case 'choice':
            return (
                <ChoiceFilter
                    {...state.bindFilter(definition.key)}
                    label={definition.label}
                    items={definition.items}
                    defaultValue={definition.defaultValue}
                />
            );
        case 'depreciation-book':
            return (
                <DepreciationBookFilter
                    {...state.bindFilter(definition.key)}
                    emptyLabel={definition.emptyLabel}
                />
            );
        case 'options':
            return (
                <OptionsFilter
                    {...state.bindMultiFilter(definition.key)}
                    label={definition.label}
                    items={definition.items}
                />
            );
        default: {
            const exhaustive: never = definition;

            return exhaustive;
        }
    }
}

/** Merender profil filter terdaftar dan mengikat setiap kolom ke state laporan. */
export function ReportFilterEngine({ state }: { state: ReportFilterState }) {
    const { profile } = state;
    const options = profile.options.filter((filter) =>
        showReportControl(filter, state.filters),
    );

    return (
        <ReportFilterBar
            canReset={state.hasActiveFilters}
            onReset={state.resetFilters}
            presets={profile.showPresets ? state.presets : undefined}
            dates={profile.dates}
            notice={state.notice}
        >
            {options.length > 0 && (
                <CollapsibleSectionGroup defaultValue={['options']}>
                    <CollapsibleSection value="options" title="Opsi">
                        <div className="grid gap-3">
                            {options.map((definition) => (
                                <ReportFilterControl
                                    key={definition.key}
                                    definition={definition}
                                    state={state}
                                />
                            ))}
                        </div>
                    </CollapsibleSection>
                </CollapsibleSectionGroup>
            )}
            <AdditionalFilters
                state={state.additional}
                profile={profile}
                renderControl={(definition) => (
                    <ReportFilterControl
                        key={definition.key}
                        definition={definition}
                        state={state}
                    />
                )}
            />
        </ReportFilterBar>
    );
}
