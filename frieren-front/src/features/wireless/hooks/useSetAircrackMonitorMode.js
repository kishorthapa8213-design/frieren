/*
 * Project: Frieren Framework
 * Copyright (C) 2026 DSR! <xchwarze@gmail.com>
 * SPDX-License-Identifier: LGPL-3.0-or-later
 */
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-toastify';

import useAuthenticatedMutation from '@src/hooks/useAuthenticatedMutation.js';
import { fetchPost } from '@src/services/fetchService.js';
import { sleep } from '@src/helpers/actionsHelper.js';
import { WIRELESS_GET_WIRELESS_OVERVIEW } from '@src/features/wireless/helpers/queryKeys.js';

const useSetAircrackMonitorMode = () => {
    const queryClient = useQueryClient();

    return useAuthenticatedMutation({
        mutationFn: ({ device, enabled }) => fetchPost({
            module: 'wireless',
            action: 'setAircrackMonitorMode',
            device,
            enabled,
        }),
        onSuccess: async (_, { enabled }) => {
            toast.success(enabled
                ? 'airmon-ng monitor mode enabled'
                : 'airmon-ng monitor mode disabled');
            await sleep(1500);
            await queryClient.invalidateQueries({
                queryKey: [WIRELESS_GET_WIRELESS_OVERVIEW],
            });
        },
        onError: (error) => {
            toast.error(error.message || 'Unable to change airmon-ng monitor mode');
        },
    });
};

export default useSetAircrackMonitorMode;
