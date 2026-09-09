import { useState, useEffect } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@apperp/ui/button';
import { ActionButton } from '@apperp/ui/action-button';
import { RecordActionBar } from '@apperp/ui/record-action-bar';
import { PersonForm } from '@/components/global-address-book/person-form';
import { OrganizationForm } from '@/components/global-address-book/organization-form';
import { AddressSection } from '@/components/global-address-book/address-section';
import { RelationshipSection } from '@/components/global-address-book/relationship-section';
import { ContactInformationSection } from '@/components/global-address-book/contact-information-section';
import { RolesSection } from '@/components/global-address-book/roles-section';
import type {
    PartyType,
    AddressItem,
    RelationshipItem,
    ContactItem,
    PartyRoleItem,
} from '@/types/global-address-book';

interface PartyTypeItem {
    code: string;
    name: string;
}

interface GlobalAddressBookProps {
    partyTypes?: PartyTypeItem[];
}

export default function GlobalAddressBook({ partyTypes }: GlobalAddressBookProps) {
    const { url } = usePage();
    const querySection = new URLSearchParams(url.split('?')[1] || '').get('section') || 'general';
    const [partyType, setPartyType] = useState<PartyType>('organization');

    const typeOptions = partyTypes && partyTypes.length > 0
        ? partyTypes
            .filter((t) => t.code === 'person' || t.code === 'organization')
            .map((t) => ({ value: t.code, label: t.name }))
        : [
            { value: 'organization', label: 'Organisasi' },
            { value: 'person', label: 'Perorangan' },
        ];

    const { data, setData, processing, errors, reset } = useForm<Record<string, any>>({
        party_id: '000004055',
        type: 'organization',
        active: true,
        language: '',
        // Person fields
        personal_title: null,
        first_name: '',
        middle_name: '',
        last_name_prefix: '',
        last_name: '',
        personal_suffix: null,
        search_name: '',
        payment_priority: '',
        initials: '',
        known_as: '',
        professional_title: '',
        professional_suffix: '',
        phonetic_first: '',
        phonetic_middle: '',
        phonetic_last: '',
        display_as: null,
        gender: null,
        marital_status: null,
        birthday: '',
        anniversary: '',
        children: '',
        hobbies: '',
        address_books: null,
        memo: '',
        // Organization fields
        name: '',
        number_of_employees: 0,
        organization_number: '',
        abc_code: null,
        duns_number: '',
        phonetic_name: '',
        // Sub-entities state
        addresses: [] as AddressItem[],
        relationships: [] as RelationshipItem[],
        contacts: [] as ContactItem[],
        roles: [] as PartyRoleItem[],
    });

    // Smooth scroll ke target section ketika link sidebar Shell (biru) diklik
    useEffect(() => {
        if (querySection) {
            const targetElement = document.getElementById(querySection);
            if (targetElement) {
                targetElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }, [querySection]);

    // Scrollspy dengan Intersection Observer API untuk sinkronisasi realtime status aktif ke Sidebar Shell
    useEffect(() => {
        const sectionIds = ['general', 'addresses', 'relationships', 'contacts', 'roles'];
        const elements = sectionIds
            .map((id) => document.getElementById(id))
            .filter((el): el is HTMLElement => el !== null);

        if (elements.length === 0) return;

        const observer = new IntersectionObserver(
            (entries) => {
                const intersectingEntries = entries.filter((entry) => entry.isIntersecting);
                if (intersectingEntries.length > 0) {
                    intersectingEntries.sort(
                        (a, b) => Math.abs(a.boundingClientRect.top) - Math.abs(b.boundingClientRect.top),
                    );
                    const activeId = intersectingEntries[0].target.id;
                    window.dispatchEvent(
                        new CustomEvent('coreerp:section-change', {
                            detail: { section: activeId },
                        }),
                    );
                }
            },
            {
                root: null,
                rootMargin: '-10% 0px -60% 0px',
                threshold: [0, 0.2, 0.4, 0.6, 0.8, 1],
            },
        );

        elements.forEach((el) => observer.observe(el));

        return () => {
            elements.forEach((el) => observer.unobserve(el));
            observer.disconnect();
        };
    }, [partyType]);

    const handleFieldChange = (field: string, value: any) => {
        setData((prev) => ({
            ...prev,
            [field]: value,
        }));
    };

    const handleTypeChange = (newType: string | null) => {
        if (!newType) return;
        const selected = newType as PartyType;
        setPartyType(selected);
        setData((prev) => ({
            ...prev,
            type: selected,
            party_id: selected === 'person' ? '000004584' : '000004055',
            name: selected === 'person' ? '' : prev.name || '',
        }));
    };

    const handleSubmit = (e?: React.FormEvent) => {
        if (e) e.preventDefault();
        alert('Data berhasil disimpan secara lokal.');
    };

    const currentPartyName =
        partyType === 'person'
            ? [data.first_name, data.middle_name, data.last_name].filter(Boolean).join(' ') || 'Perorangan Baru'
            : data.name || 'Organisasi Baru';

    return (
        <>
            <Head title="Buku Alamat Global" />

            {/* TOP RECORD ACTION BAR */}
            <RecordActionBar
                title="Buku Alamat Global"
                trailing={
                    <div className="flex items-center gap-2">
                        <ActionButton
                            action="create"
                            size="sm"
                            onClick={() => {
                                reset();
                                setData('party_id', `00000${Math.floor(1000 + Math.random() * 9000)}`);
                            }}
                        >
                            Tambah Pihak
                        </ActionButton>

                        <ActionButton
                            action="archive"
                            size="sm"
                            onClick={() => {
                                alert(`Pihak ${data.party_id} berhasil diarsipkan.`);
                            }}
                        >
                            Arsipkan
                        </ActionButton>

                        <div className="h-4 w-px bg-border mx-1" />

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => reset()}
                        >
                            Batal
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            disabled={processing}
                            onClick={() => handleSubmit()}
                        >
                            Simpan
                        </Button>
                    </div>
                }
            />

            <main className="mx-auto flex w-full max-w-7xl flex-col gap-8 p-6">
                {/* 1. SEKSI GENERAL */}
                <section id="general" className="scroll-mt-6">
                    {partyType === 'person' ? (
                        <PersonForm
                            data={data}
                            partyType={partyType}
                            typeOptions={typeOptions}
                            onTypeChange={handleTypeChange}
                            onChange={handleFieldChange}
                            errors={errors}
                        />
                    ) : (
                        <OrganizationForm
                            data={data}
                            partyType={partyType}
                            typeOptions={typeOptions}
                            onTypeChange={handleTypeChange}
                            onChange={handleFieldChange}
                            errors={errors}
                        />
                    )}
                </section>

                {/* 2. SEKSI ADDRESSES */}
                <section id="addresses" className="scroll-mt-6">
                    <AddressSection
                        addresses={data.addresses || []}
                        onChange={(addresses) => handleFieldChange('addresses', addresses)}
                    />
                </section>

                {/* 3. SEKSI RELATIONSHIPS */}
                <section id="relationships" className="scroll-mt-6">
                    <RelationshipSection
                        relationships={data.relationships || []}
                        currentPartyId={data.party_id}
                        currentPartyName={currentPartyName}
                        onChange={(relationships) => handleFieldChange('relationships', relationships)}
                    />
                </section>

                {/* 4. SEKSI CONTACT INFORMATION */}
                <section id="contacts" className="scroll-mt-6">
                    <ContactInformationSection
                        contacts={data.contacts || []}
                        onChange={(contacts) => handleFieldChange('contacts', contacts)}
                    />
                </section>

                {/* 5. SEKSI ROLES */}
                <section id="roles" className="scroll-mt-6">
                    <RolesSection roles={data.roles || []} />
                </section>
            </main>
        </>
    );
}

GlobalAddressBook.layout = {
    breadcrumbs: [
        {
            title: 'Buku alamat',
            href: '/settings/global-address-book',
        },
        {
            title: 'Buku alamat global',
            href: '/settings/global-address-book',
        },
    ],
};
