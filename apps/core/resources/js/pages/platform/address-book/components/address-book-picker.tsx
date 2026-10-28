import { MultiSelect } from '@apperp/ui/multi-select';
import { Select } from '@apperp/ui/select';
import type { RefObject } from 'react';
import type { AddressBook } from '../types';
import { mergeAddressBooks, useAddressBooks } from './use-address-books';

type Props = {
    selectedBooks?: AddressBook[];
    portalContainer?: RefObject<HTMLElement | null>;
} & (
    | {
          multiple: true;
          value: string[];
          onValueChange: (value: string[]) => void;
      }
    | {
          multiple?: false;
          value: string;
          onValueChange: (value: string) => void;
      }
);

export function AddressBookPicker(props: Props) {
    const { list, known, query, setQuery } = useAddressBooks();
    const books = mergeAddressBooks(props.selectedBooks ?? [], known);
    const items = books.map((book) => ({
        value: book.id,
        label: book.name + (book.deleted_at ? ' (terarsip)' : ''),
    }));
    const common = {
        label: 'Buku alamat',
        incremental: list,
        searchValue: query,
        onSearchChange: setQuery,
        searchPlaceholder: 'Cari buku alamat…',
        emptyMessage: 'Buku alamat tidak ditemukan.',
        portalContainer: props.portalContainer,
        knownValues: items.map((item) => item.value),
    };

    if (props.multiple) {
        return (
            <MultiSelect
                {...common}
                items={items}
                visibleValues={list.items.map((book) => book.id)}
                value={props.value}
                onValueChange={props.onValueChange}
            />
        );
    }

    return (
        <Select
            {...common}
            items={[{ value: '', label: 'Semua buku alamat' }, ...items]}
            visibleValues={['', ...list.items.map((book) => book.id)]}
            value={props.value}
            onValueChange={(value) => props.onValueChange(value ?? '')}
        />
    );
}
