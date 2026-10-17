/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 *
 * ModeAwareFields' own field-selection logic is covered by ModeAwareFields.test.jsx; here its
 * two data hooks are mocked directly (rather than mocking fetchPost + a QueryClient like that
 * suite) so ModeAwareFields renders its real "ap" fields without pulling react-query into this
 * file — this test is only concerned with InterfaceForm's own add/edit submit wiring and
 * validation, using defaultValues shaped the way InterfaceFormLoader would produce them.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import InterfaceForm from '@src/features/wireless/components/InterfaceFormModal/InterfaceForm.jsx';
import useAddInterface from '@src/features/wireless/hooks/useAddInterface.js';
import useSetInterfaceConfig from '@src/features/wireless/hooks/useSetInterfaceConfig.js';
import useGetNetworkInterfaces from '@src/features/wireless/hooks/useGetNetworkInterfaces.js';
import useGetEncryptionOptions from '@src/features/wireless/hooks/useGetEncryptionOptions.js';

vi.mock('@src/features/wireless/hooks/useAddInterface.js', () => ({ default: vi.fn() }));
vi.mock('@src/features/wireless/hooks/useSetInterfaceConfig.js', () => ({ default: vi.fn() }));
vi.mock('@src/features/wireless/hooks/useGetNetworkInterfaces.js', () => ({ default: vi.fn() }));
vi.mock('@src/features/wireless/hooks/useGetEncryptionOptions.js', () => ({ default: vi.fn() }));

const addInterface = vi.fn();
const setInterfaceConfig = vi.fn();

const apDefaults = {
    ssid: 'guest-net',
    mode: 'ap',
    network: 'lan',
    encryption: 'none',
    key: '',
    hidden: false,
    disabled: false,
    isManagement: false,
    isRecon: false,
    ieee80211w: '0',
    bssid: '',
};

describe('InterfaceForm', () => {
    beforeEach(() => {
        addInterface.mockReset().mockResolvedValue({});
        setInterfaceConfig.mockReset().mockResolvedValue({});
        useAddInterface.mockReturnValue({ mutateAsync: addInterface });
        useSetInterfaceConfig.mockReturnValue({ mutateAsync: setInterfaceConfig });
        useGetNetworkInterfaces.mockReturnValue({
            data: { interfaces: [{ name: 'lan' }, { name: 'guest' }] },
            isError: false,
        });
        useGetEncryptionOptions.mockReturnValue({
            data: { options: [{ value: 'none', label: 'None' }, { value: 'psk2+ccmp', label: 'WPA2-PSK' }] },
            isError: false,
        });
    });

    it('adds a new interface on the given radio and reports the new section back', async () => {
        const onHide = vi.fn();
        const onInterfaceSaved = vi.fn();
        addInterface.mockResolvedValue({ section: 'wlan5' });

        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={onHide}
                defaultValues={apDefaults}
                onInterfaceSaved={onInterfaceSaved}
            />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Add Interface' }));

        await waitFor(() => expect(addInterface).toHaveBeenCalledWith({ radio: 'radio0', ...apDefaults }));
        expect(setInterfaceConfig).not.toHaveBeenCalled();
        await waitFor(() => expect(onInterfaceSaved).toHaveBeenCalledWith('wlan5'));
        await waitFor(() => expect(onHide).toHaveBeenCalledTimes(1));
    });

    it('still closes the modal after adding, even when the backend reports no new section', async () => {
        const onHide = vi.fn();
        const onInterfaceSaved = vi.fn();
        addInterface.mockResolvedValue({});

        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={onHide}
                defaultValues={apDefaults}
                onInterfaceSaved={onInterfaceSaved}
            />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Add Interface' }));

        await waitFor(() => expect(onHide).toHaveBeenCalledTimes(1));
        expect(onInterfaceSaved).not.toHaveBeenCalled();
    });

    it('submits Linux access points without OpenWrt-only settings', async () => {
        const onHide = vi.fn();
        const onInterfaceSaved = vi.fn();
        addInterface.mockResolvedValue({ section: 'ap-test' });

        render(
            <InterfaceForm
                radio={'wlan1'}
                onHide={onHide}
                defaultValues={{ ...apDefaults, network: '' }}
                onInterfaceSaved={onInterfaceSaved}
                linuxWireless={true}
                wirelessModes={['sta', 'ap']}
            />
        );

        expect(screen.getByRole('combobox', { name: 'Mode' })).toHaveDisplayValue('Access Point');
        expect(screen.queryByLabelText('Network')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Hidden AP')).toBeInTheDocument();
        expect(screen.queryByLabelText('Management Frame Protection')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Management Interface')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Add Interface' }));

        await waitFor(() => expect(addInterface).toHaveBeenCalledWith({
            radio: 'wlan1',
            ...apDefaults,
            network: '',
        }));
        expect(onInterfaceSaved).toHaveBeenCalledWith('ap-test');
        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('submits Linux monitor interfaces without SSID or network fields', async () => {
        const onHide = vi.fn();
        const onInterfaceSaved = vi.fn();
        addInterface.mockResolvedValue({ section: 'monitor-test' });
        const monitorDefaults = {
            ...apDefaults,
            ssid: '',
            mode: 'monitor',
            network: '',
            encryption: '',
        };

        render(
            <InterfaceForm
                radio={'wlan1'}
                onHide={onHide}
                defaultValues={monitorDefaults}
                onInterfaceSaved={onInterfaceSaved}
                linuxWireless={true}
                wirelessModes={['sta', 'monitor']}
            />
        );

        expect(screen.queryByLabelText('Network')).not.toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'Mode' })).toHaveDisplayValue('Monitor');
        fireEvent.click(screen.getByRole('button', { name: 'Add Interface' }));

        await waitFor(() => expect(addInterface).toHaveBeenCalledWith({
            radio: 'wlan1',
            ...monitorDefaults,
        }));
        expect(onInterfaceSaved).toHaveBeenCalledWith('monitor-test');
        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('keeps the current monitor mode selectable when editing a Linux monitor profile', () => {
        render(
            <InterfaceForm
                section={'monitor-test'}
                radio={'wlan1'}
                onHide={vi.fn()}
                defaultValues={{ ...apDefaults, ssid: '', mode: 'monitor', network: '', encryption: '' }}
                linuxWireless={true}
                wirelessModes={['monitor']}
            />
        );

        expect(screen.getByRole('combobox', { name: 'Mode' })).toHaveDisplayValue('Monitor');
        expect(screen.getByRole('option')).toHaveTextContent('Monitor');
    });

    it('edits an existing interface by its UCI section instead of adding a new one', async () => {
        const onHide = vi.fn();
        const onInterfaceSaved = vi.fn();

        render(
            <InterfaceForm
                section={'wlan0'}
                onHide={onHide}
                defaultValues={apDefaults}
                onInterfaceSaved={onInterfaceSaved}
            />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(setInterfaceConfig).toHaveBeenCalledWith({ section: 'wlan0', ...apDefaults }));
        expect(addInterface).not.toHaveBeenCalled();
        await waitFor(() => expect(onInterfaceSaved).toHaveBeenCalledWith('wlan0'));
        expect(onHide).toHaveBeenCalledTimes(1);
    });

    it('blocks submission and shows a field error when SSID is left blank', async () => {
        const onHide = vi.fn();

        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={onHide}
                defaultValues={{ ...apDefaults, ssid: '' }}
                onInterfaceSaved={vi.fn()}
            />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Add Interface' }));

        expect(await screen.findByText('SSID is mandatory')).toBeInTheDocument();
        expect(addInterface).not.toHaveBeenCalled();
        expect(onHide).not.toHaveBeenCalled();
    });

    it('requires a passphrase once a non-open encryption is selected', async () => {
        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={vi.fn()}
                defaultValues={{ ...apDefaults, encryption: 'psk2+ccmp', key: '' }}
                onInterfaceSaved={vi.fn()}
            />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Add Interface' }));

        expect(await screen.findByText('Key is required')).toBeInTheDocument();
        expect(addInterface).not.toHaveBeenCalled();
    });

    it('cancels without submitting anything', () => {
        const onHide = vi.fn();

        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={onHide}
                defaultValues={apDefaults}
                onInterfaceSaved={vi.fn()}
            />
        );

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onHide).toHaveBeenCalledTimes(1);
        expect(addInterface).not.toHaveBeenCalled();
    });

    it('warns when a client (sta) interface is bound to the lan network zone', () => {
        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={vi.fn()}
                defaultValues={{ ...apDefaults, mode: 'sta', network: 'lan' }}
                onInterfaceSaved={vi.fn()}
            />
        );

        expect(screen.getByText(/usually belongs on/)).toBeInTheDocument();
    });

    it('does not warn for an ap interface on lan', () => {
        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={vi.fn()}
                defaultValues={{ ...apDefaults, mode: 'ap', network: 'lan' }}
                onInterfaceSaved={vi.fn()}
            />
        );
        expect(screen.queryByText(/usually belongs on/)).not.toBeInTheDocument();
    });

    it('warns for a sta interface on plain wan too -- it belongs to the ethernet port, not wifi', () => {
        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={vi.fn()}
                defaultValues={{ ...apDefaults, mode: 'sta', network: 'wan' }}
                onInterfaceSaved={vi.fn()}
            />
        );

        expect(screen.getByText(/usually belongs on/)).toBeInTheDocument();
    });

    it('does not warn for a sta interface on wwan', () => {
        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={vi.fn()}
                defaultValues={{ ...apDefaults, mode: 'sta', network: 'wwan' }}
                onInterfaceSaved={vi.fn()}
            />
        );
        expect(screen.queryByText(/usually belongs on/)).not.toBeInTheDocument();
    });

    it('submits successfully in monitor mode without the AP-only ssid/network/encryption fields', async () => {
        const monitorDefaults = {
            ssid: '',
            mode: 'monitor',
            network: '',
            encryption: 'none',
            key: '',
            hidden: false,
            disabled: false,
            isManagement: false,
            isRecon: true,
        };

        render(
            <InterfaceForm
                radio={'radio0'}
                onHide={vi.fn()}
                defaultValues={monitorDefaults}
                onInterfaceSaved={vi.fn()}
            />
        );

        expect(screen.queryByLabelText('SSID')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Add Interface' }));

        await waitFor(() => expect(addInterface).toHaveBeenCalledWith({ radio: 'radio0', ...monitorDefaults }));
    });
});
