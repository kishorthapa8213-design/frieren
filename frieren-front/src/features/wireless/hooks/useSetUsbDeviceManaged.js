/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-toastify';

import useAuthenticatedMutation from '@src/hooks/useAuthenticatedMutation.js';
import { fetchPost } from '@src/services/fetchService.js';
import { WIRELESS_GET_WIRELESS_OVERVIEW } from '@src/features/wireless/helpers/queryKeys.js';

const useSetUsbDeviceManaged = () => {
    const queryClient = useQueryClient();

    return useAuthenticatedMutation({
        mutationFn: ({ device, managed }) => fetchPost({
            module: 'wireless',
            action: 'setUsbDeviceManaged',
            device,
            managed,
        }),
        onSuccess: async (_, { managed }) => {
            toast.success(managed
                ? 'NetworkManager management enabled'
                : 'USB Wi-Fi adapter is now unmanaged');
            await queryClient.invalidateQueries({
                queryKey: [WIRELESS_GET_WIRELESS_OVERVIEW],
            });
        },
        onError: (error) => {
            toast.error(error.message || 'Unable to change USB Wi-Fi management');
        },
    });
};

export default useSetUsbDeviceManaged;
