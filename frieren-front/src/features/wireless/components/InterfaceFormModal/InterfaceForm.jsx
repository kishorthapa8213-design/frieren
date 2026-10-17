/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: PolyForm-Noncommercial-1.0.0
 * More info at: https://github.com/xchwarze/frieren
 */
import { useCallback } from 'react';
import PropTypes from 'prop-types';

import Button from '@src/components/Button';
import FormProvider from '@src/components/Form/FormProvider';
import ReadOnlyField from '@src/components/Form/ReadOnlyField';
import SelectField from '@src/components/Form/SelectField';
import SwitchField from '@src/components/Form/SwitchField';
import SubmitButton from '@src/components/Form/SubmitButton';
import FormActions from '@src/components/FormActions';
import { MODE_OPTIONS } from '@src/features/wireless/helpers/constants.js';
import { interfaceSchema, linuxInterfaceSchema } from '@src/features/wireless/helpers/validationSchemas.js';
import useAddInterface from '@src/features/wireless/hooks/useAddInterface.js';
import useSetInterfaceConfig from '@src/features/wireless/hooks/useSetInterfaceConfig.js';
import ModeAwareFields from './ModeAwareFields';
import NetworkZoneWarning from './NetworkZoneWarning';

const InterfaceForm = ({ radio, section, onHide, defaultValues, onInterfaceSaved, linuxWireless, wirelessModes }) => {
    const isEditMode = !!section;
    const supportedModeOptions = wirelessModes
        ? MODE_OPTIONS.filter(({ value }) => wirelessModes.includes(value))
        : MODE_OPTIONS;
    const modeOptions = linuxWireless && isEditMode
        ? supportedModeOptions.filter(({ value }) => value === defaultValues.mode)
        : supportedModeOptions;
    const { mutateAsync: addInterface } = useAddInterface();
    const { mutateAsync: setInterfaceConfig } = useSetInterfaceConfig();

    const handleSubmit = useCallback(async (values) => {
        if (isEditMode) {
            await setInterfaceConfig({ section, ...values });
            onInterfaceSaved?.(section);
        } else {
            const result = await addInterface({ radio, ...values });
            if (result?.section) {
                onInterfaceSaved?.(result.section);
            }
        }
        onHide();
    }, [isEditMode, section, radio, addInterface, setInterfaceConfig, onHide, onInterfaceSaved]);

    return (
        <FormProvider
            autoComplete={'off'}
            schema={linuxWireless ? linuxInterfaceSchema : interfaceSchema}
            onSubmit={handleSubmit}
            defaultValues={defaultValues}
        >
            {!linuxWireless && <NetworkZoneWarning />}
            <ReadOnlyField
                label={isEditMode ? 'Section' : 'Radio'}
                value={isEditMode ? section : radio}
            />
            <SelectField
                name={'mode'}
                label={'Mode'}
                options={modeOptions}
            />
            {linuxWireless && isEditMode && (
                <div className={'text-body-secondary small mb-2'}>
                    Remove and add the interface again to change between client and monitor modes.
                </div>
            )}
            <ModeAwareFields radio={radio} linuxWireless={linuxWireless} />
            <SwitchField
                name={'disabled'}
                label={'Disabled'}
            />
            <FormActions>
                <SubmitButton label={isEditMode ? 'Save' : 'Add Interface'} />
                <Button variant={'secondary'} onClick={onHide} label={'Cancel'} />
            </FormActions>
        </FormProvider>
    );
};

InterfaceForm.propTypes = {
    radio: PropTypes.string,
    section: PropTypes.string,
    onHide: PropTypes.func.isRequired,
    defaultValues: PropTypes.object.isRequired,
    onInterfaceSaved: PropTypes.func,
    linuxWireless: PropTypes.bool,
    wirelessModes: PropTypes.arrayOf(PropTypes.string),
};

export default InterfaceForm;
