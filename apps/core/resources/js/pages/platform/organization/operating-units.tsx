import OrganizationDirectory from './components/organization-directory';
import type { Props } from './components/organization-types';

export default function OperatingUnits(
    props: Pick<
        Props,
        'scopeKey' | 'canManage' | 'organizations' | 'operatingUnitTypes'
    >,
) {
    return (
        <OrganizationDirectory
            key={props.scopeKey}
            {...props}
            classification="operating_unit"
            title="Operating unit"
        />
    );
}
OperatingUnits.layout = {
    breadcrumbs: [
        { title: 'Operating unit', href: '/settings/operating-units' },
    ],
};
